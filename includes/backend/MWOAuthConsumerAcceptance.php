<?php

namespace MediaWiki\Extensions\OAuth;

use Wikimedia\Rdbms\DBConnRef;

/**
 (c) Aaron Schulz 2013, GPL

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along
 with this program; if not, write to the Free Software Foundation, Inc.,
 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
 http://www.gnu.org/copyleft/gpl.html
*/

/**
 * Representation of an OAuth consumer acceptance.
 * Created when the user clicks through the OAuth authorization dialog, this allows
 * the specified consumer to perform actions in the name of the user
 * (subject to the grant and wiki restrictions stored in the acceptance object).
 */
class MWOAuthConsumerAcceptance extends MWOAuthDAO {
	/** @var int Unique ID */
	protected $id;
	/** @var string Wiki ID the application can be used on (or "*" for all) */
	protected $wiki;
	/** @var int Publisher user ID (on central wiki) */
	protected $userId;
	/** @var int */
	protected $consumerId;
	/** @var string Hex token */
	protected $accessToken;
	/** @var string Secret HMAC key */
	protected $accessSecret;
	/** @var array List of grants */
	protected $grants;
	/** @var string TS_MW timestamp of acceptance */
	protected $accepted;

	protected static function getSchema() {
		return [
			'table'          => 'oauth_accepted_consumer',
			'fieldColumnMap' => [
				'id'           => 'oaac_id',
				'wiki'         => 'oaac_wiki',
				'userId'       => 'oaac_user_id',
				'consumerId'   => 'oaac_consumer_id',
				'accessToken'  => 'oaac_access_token',
				'accessSecret' => 'oaac_access_secret',
				'grants'       => 'oaac_grants',
				'accepted'     => 'oaac_accepted'
			],
			'idField'        => 'id',
			'autoIncrField'  => 'id',
		];
	}

	protected static function getFieldPermissionChecks() {
		return [
			'wiki'         => 'userCanSee',
			'userId'       => 'userCanSee',
			'consumerId'   => 'userCanSee',
			'accessToken'  => 'userCanSeePrivate',
			'accessSecret' => 'userCanSeeSecret',
			'grants'       => 'userCanSee',
			'accepted'     => 'userCanSee',
		];
	}

	/**
	 * @param DBConnRef $db
	 * @param string $token Access token
	 * @param int $flags MWOAuthConsumerAcceptance::READ_* bitfield
	 * @return MWOAuthConsumerAcceptance|bool
	 */
	public static function newFromToken( DBConnRef $db, $token, $flags = 0 ) {
		$row = $db->selectRow( static::getTable(),
			array_values( static::getFieldColumnMap() ),
			[ 'oaac_access_token' => (string)$token ],
			__METHOD__,
			( $flags & self::READ_LOCKING ) ? [ 'FOR UPDATE' ] : []
		);

		if ( $row ) {
			$consumer = new self();
			$consumer->loadFromRow( $db, $row );
			return $consumer;
		} else {
			return false;
		}
	}

	/**
	 * @param DBConnRef $db
	 * @param String $userId of user who authorized (central wiki's id)
	 * @param MWOAuthConsumer $consumer
	 * @param String $wiki wiki associated with the acceptance
	 * @param int $flags MWOAuthConsumerAcceptance::READ_* bitfield
	 * @return MWOAuthConsumerAcceptance|bool
	 */
	public static function newFromUserConsumerWiki(
		DBConnRef $db, $userId, $consumer, $wiki, $flags = 0
	) {
		$row = $db->selectRow( static::getTable(),
			array_values( static::getFieldColumnMap() ),
			[
				'oaac_user_id' => (int)$userId,
				'oaac_consumer_id' => $consumer->getId(),
				'oaac_wiki' => (string)$wiki
			],
			__METHOD__,
			( $flags & self::READ_LOCKING ) ? [ 'FOR UPDATE' ] : []
		);

		if ( $row ) {
			$consumer = new self();
			$consumer->loadFromRow( $db, $row );
			return $consumer;
		} else {
			return false;
		}
	}

	/**
	 * Database ID.
	 * @return int
	 */
	public function getId() {
		return $this->get( 'id' );
	}

	/**
	 * Wiki on which the user has authorized the consumer to access their account. Wiki ID or '*'
	 * for all.
	 * @return string
	 */
	public function getWiki() {
		return $this->get( 'wiki' );
	}

	/**
	 * Central user ID of the authorizing user.
	 * @return int
	 */
	public function getUserId() {
		return $this->get( 'userId' );
	}

	/**
	 * Database ID of the consumer.
	 * @return int
	 */
	public function getConsumerId() {
		return $this->get( 'consumerId' );
	}

	/**
	 * The access token for the OAuth protocol
	 * @return string
	 */
	public function getAccessToken() {
		return $this->get( 'accessToken' );
	}

	/**
	 * Secret key used to derive the access secret for the OAuth protocol.
	 * The actual access secret will be calculated via MWOAuthUtils::hmacDBSecret() to mitigate
	 * DB leaks.
	 * @return string
	 */
	public function getAccessSecret() {
		return $this->get( 'accessSecret' );
	}

	/**
	 * The list of grants which have been granted.
	 * @return string[]
	 */
	public function getGrants() {
		return $this->get( 'grants' );
	}

	/**
	 * Date of the authorization, in TS_MW format.
	 * @return string
	 */
	public function getAccepted() {
		return $this->get( 'accepted' );
	}

	protected function normalizeValues() {
		$this->userId = (int)$this->userId;
		$this->consumerId = (int)$this->consumerId;
		$this->accepted = wfTimestamp( TS_MW, $this->accepted );
		$this->grants = (array)$this->grants; // sanity
	}

	protected function encodeRow( DBConnRef $db, $row ) {
		// For compatibility with other wikis in the farm, un-remap some grants
		foreach ( MWOAuthConsumer::$mapBackCompatGrants as $old => $new ) {
			while ( ( $i = array_search( $new, $row['oaac_grants'], true ) ) !== false ) {
				$row['oaac_grants'][$i] = $old;
			}
		}

		$row['oaac_grants'] = \FormatJson::encode( $row['oaac_grants'] );
		$row['oaac_accepted'] = $db->timestamp( $row['oaac_accepted'] );
		return $row;
	}

	protected function decodeRow( DBConnRef $db, $row ) {
		$row['oaac_grants'] = \FormatJson::decode( $row['oaac_grants'], true );
		$row['oaac_accepted'] = wfTimestamp( TS_MW, $row['oaac_accepted'] );

		// For backwards compatibility, remap some grants
		foreach ( MWOAuthConsumer::$mapBackCompatGrants as $old => $new ) {
			while ( ( $i = array_search( $old, $row['oaac_grants'], true ) ) !== false ) {
				$row['oaac_grants'][$i] = $new;
			}
		}

		return $row;
	}

	protected function userCanSee( $name, \IContextSource $context ) {
		$centralUserId = MWOAuthUtils::getCentralIdFromLocalUser( $context->getUser() );
		if ( $this->userId != $centralUserId
			&& !$context->getUser()->isAllowed( 'mwoauthviewprivate' )
		) {
			return $context->msg( 'mwoauth-field-private' );
		} else {
			return true;
		}
	}

	protected function userCanSeePrivate( $name, \IContextSource $context ) {
		if ( !$context->getUser()->isAllowed( 'mwoauthviewprivate' ) ) {
			return $context->msg( 'mwoauth-field-private' );
		} else {
			return $this->userCanSee( $name, $context );
		}
	}

	protected function userCanSeeSecret( $name, \IContextSource $context ) {
		return $context->msg( 'mwoauth-field-private' );
	}
}
