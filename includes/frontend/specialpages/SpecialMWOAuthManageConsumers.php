<?php

namespace MediaWiki\Extensions\OAuth;

use Wikimedia\Rdbms\DBConnRef;

/**
 * (c) Aaron Schulz 2013, GPL
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
use OOUI\HtmlSnippet;

/**
 * Special page for listing the queue of consumer requests and managing
 * their approval/rejection and also for listing approved/disabled consumers
 */
class SpecialMWOAuthManageConsumers extends \SpecialPage {
	/** @var bool|int An MWOAuthConsumer::STAGE_* constant on queue/list subpages, false otherwise*/
	protected $stage = false;
	/** @var string A stage key from MWOAuthConsumer::$stageNames */
	protected $stageKey;

	/**
	 * Stages which are shown in a queue (they are in an actionable state and can form a backlog)
	 * @var array
	 */
	public static $queueStages = [ MWOAuthConsumer::STAGE_PROPOSED,
		MWOAuthConsumer::STAGE_REJECTED, MWOAuthConsumer::STAGE_EXPIRED ];

	/**
	 * Stages which cannot form a backlog and are shown in a list
	 * @var array
	 */
	public static $listStages = [ MWOAuthConsumer::STAGE_APPROVED,
		MWOAuthConsumer::STAGE_DISABLED ];

	public function __construct() {
		parent::__construct( 'OAuthManageConsumers', 'mwoauthmanageconsumer' );
	}

	public function doesWrites() {
		return true;
	}

	public function execute( $par ) {
		global $wgMWOAuthReadOnly;

		$user = $this->getUser();

		$this->setHeaders();
		$this->getOutput()->disallowUserJs();
		$this->addHelpLink( 'Help:OAuth' );

		if ( !$user->isLoggedIn() ) {
			$this->getOutput()->addWikiMsg( 'mwoauthmanageconsumers-notloggedin' );
			return;
		} elseif ( !$user->isAllowed( 'mwoauthmanageconsumer' ) ) {
			throw new \PermissionsError( 'mwoauthmanageconsumer' );
		}

		if ( $wgMWOAuthReadOnly ) {
			throw new \ErrorPageError( 'mwoauth-error', 'mwoauth-db-readonly' );
		}

		// Format is Special:OAuthManageConsumers[/<stage>|/<consumer key>]
		// B/C format is Special:OAuthManageConsumers/<stage>/<consumer key>
		$consumerKey = null;
		$navigation = explode( '/', $par );
		if ( count( $navigation ) === 2 ) {
			$this->stage = false;
			$consumerKey = $navigation[1];
		} elseif ( count( $navigation ) === 1 && $navigation[0] ) {
			$this->stage = array_search( $navigation[0], MWOAuthConsumer::$stageNames, true );
			if ( $this->stage !== false ) {
				$consumerKey = null;
				$this->stageKey = $navigation[0];
			} else {
				$consumerKey = $navigation[0];
			}
		}

		if ( $consumerKey ) {
			$this->handleConsumerForm( $consumerKey );
		} elseif ( $this->stage !== false ) {
			$this->showConsumerList();
		} else {
			$this->showMainHub();
		}

		$this->addQueueSubtitleLinks( $consumerKey );

		$this->getOutput()->addModuleStyles( 'ext.MWOAuth.styles' );
	}

	/**
	 * Show other sub-queue links. Grey out the current one.
	 * When viewing a request, show them all.
	 *
	 * @param string $consumerKey
	 * @return void
	 */
	protected function addQueueSubtitleLinks( $consumerKey ) {
		$listLinks = [];
		foreach ( self::$queueStages as $stage ) {
			$stageKey = MWOAuthConsumer::$stageNames[$stage];
			if ( $consumerKey || $this->stageKey !== $stageKey ) {
				$listLinks[] = \Linker::linkKnown(
					$this->getPageTitle( $stageKey ),
					// Messages: mwoauthmanageconsumers-showproposed,
					// mwoauthmanageconsumers-showrejected, mwoauthmanageconsumers-showexpired,
					$this->msg( 'mwoauthmanageconsumers-show' . $stageKey )->escaped() );
			} else {
				$listLinks[] = $this->msg( 'mwoauthmanageconsumers-show' . $stageKey )->escaped();
			}
		}

		$linkHtml = $this->getLanguage()->pipeList( $listLinks );

		$viewall = $this->msg( 'parentheses' )->rawParams( \Linker::linkKnown(
			$this->getPageTitle(), $this->msg( 'mwoauthmanageconsumers-main' )->escaped() ) );

		$this->getOutput()->setSubtitle(
			"<strong>" . $this->msg( 'mwoauthmanageconsumers-type' )->escaped() .
			"</strong> [{$linkHtml}] <strong>{$viewall}</strong>" );
	}

	/**
	 * Show the links to all the queues and how many requests are in each.
	 * Also show the list of enabled and disabled consumers and how many there are of each.
	 *
	 * @return void
	 */
	protected function showMainHub() {
		$keyStageMapQ = array_intersect( array_flip( MWOAuthConsumer::$stageNames ),
			self::$queueStages );
		$keyStageMapL = array_intersect( array_flip( MWOAuthConsumer::$stageNames ),
			self::$listStages );

		$out = $this->getOutput();

		$out->addWikiMsg( 'mwoauthmanageconsumers-maintext' );

		$counts = MWOAuthUtils::getConsumerStateCounts( MWOAuthUtils::getCentralDB( DB_REPLICA ) );

		$out->wrapWikiMsg( "<p><strong>$1</strong></p>", 'mwoauthmanageconsumers-queues' );
		$out->addHTML( '<ul>' );
		foreach ( $keyStageMapQ as $stageKey => $stage ) {
			$tag = ( $stage === MWOAuthConsumer::STAGE_EXPIRED ) ? 'i' : 'b';
			$out->addHTML(
				'<li>' .
				"<$tag>" .
				\Linker::linkKnown(
					$this->getPageTitle( $stageKey ),
					// Messages: mwoauthmanageconsumers-q-proposed, mwoauthmanageconsumers-q-rejected,
					// mwoauthmanageconsumers-q-expired
					$this->msg( 'mwoauthmanageconsumers-q-' . $stageKey )->escaped()
				) .
				"</$tag> [$counts[$stage]]" .
				'</li>'
			);
		}
		$out->addHTML( '</ul>' );

		$out->wrapWikiMsg( "<p><strong>$1</strong></p>", 'mwoauthmanageconsumers-lists' );
		$out->addHTML( '<ul>' );
		foreach ( $keyStageMapL as $stageKey => $stage ) {
			$out->addHTML(
				'<li>' .
				\Linker::linkKnown(
					$this->getPageTitle( $stageKey ),
					// Messages: mwoauthmanageconsumers-l-approved, mwoauthmanageconsumers-l-disabled
					$this->msg( 'mwoauthmanageconsumers-l-' . $stageKey )->escaped()
				) .
				" [$counts[$stage]]" .
				'</li>'
			);
		}
		$out->addHTML( '</ul>' );
	}

	/**
	 * Show the form to approve/reject/disable/re-enable consumers
	 *
	 * @param string $consumerKey
	 * @throws \PermissionsError
	 */
	protected function handleConsumerForm( $consumerKey ) {
		$user = $this->getUser();
		$lang = $this->getLanguage();
		$dbr = MWOAuthUtils::getCentralDB( DB_REPLICA );
		$cmrAc = MWOAuthConsumerAccessControl::wrap(
			MWOAuthConsumer::newFromKey( $dbr, $consumerKey ), $this->getContext() );
		if ( !$cmrAc ) {
			$this->getOutput()->addWikiMsg( 'mwoauth-invalid-consumer-key' );
			return;
		} elseif ( $cmrAc->getDeleted() && !$user->isAllowed( 'mwoauthviewsuppressed' ) ) {
			throw new \PermissionsError( 'mwoauthviewsuppressed' );
		}
		$startingStage = $cmrAc->getStage();
		$pending = !in_array( $startingStage, [
			MWOAuthConsumer::STAGE_APPROVED, MWOAuthConsumer::STAGE_DISABLED ] );

		if ( $pending ) {
			$opts = [
				$this->msg( 'mwoauthmanageconsumers-approve' )->escaped() => 'approve',
				$this->msg( 'mwoauthmanageconsumers-reject' )->escaped()  => 'reject'
			];
			if ( $this->getUser()->isAllowed( 'mwoauthsuppress' ) ) {
				$msg = $this->msg( 'mwoauthmanageconsumers-rsuppress' )->escaped();
				$opts["<strong>$msg</strong>"] = 'rsuppress';
			}
		} else {
			$opts = [
				$this->msg( 'mwoauthmanageconsumers-disable' )->escaped() => 'disable',
				$this->msg( 'mwoauthmanageconsumers-reenable' )->escaped()  => 'reenable'
			];
			if ( $this->getUser()->isAllowed( 'mwoauthsuppress' ) ) {
				$msg = $this->msg( 'mwoauthmanageconsumers-dsuppress' )->escaped();
				$opts["<strong>$msg</strong>"] = 'dsuppress';
			}
		}

		$owner = $cmrAc->getUserName();

		$link = \Linker::linkKnown(
			$title = \SpecialPage::getTitleFor( 'OAuthListConsumers' ),
			$this->msg( 'mwoauthmanageconsumers-search-publisher' )->escaped(),
			[],
			[ 'publisher' => $owner ]
		);
		$ownerLink = $cmrAc->escapeForHtml( $owner ) . ' ' .
			$this->msg( 'parentheses' )->rawParams( $link )->escaped();
		$ownerOnly = $cmrAc->getDAO()->getOwnerOnly();

		$dbw = MWOAuthUtils::getCentralDB( DB_MASTER ); // @TODO: lazy handle
		$control = new MWOAuthConsumerSubmitControl( $this->getContext(), [], $dbw );
		$restrictions = $cmrAc->getRestrictions();
		$form = \HTMLForm::factory( 'ooui',
			$control->registerValidators( [
				'info' => [
					'type' => 'info',
					'raw' => true,
					'default' => MWOAuthUIUtils::generateInfoTable( [
						// Messages: mwoauth-consumer-stage-proposed, mwoauth-consumer-stage-rejected,
						// mwoauth-consumer-stage-expired, mwoauth-consumer-stage-approved,
						// mwoauth-consumer-stage-disabled
						'mwoauth-consumer-stage' => $cmrAc->getDeleted()
							? $this->msg( 'mwoauth-consumer-stage-suppressed' )
							: $this->msg( 'mwoauth-consumer-stage-' .
								MWOAuthConsumer::$stageNames[$cmrAc->getStage()] ),
						'mwoauth-consumer-key' => $cmrAc->getConsumerKey(),
						'mwoauth-consumer-name' => new HtmlSnippet( $cmrAc->get( 'name', function ( $s ) {
							$link = \Linker::linkKnown(
								\SpecialPage::getTitleFor( 'OAuthListConsumers' ),
								$this->msg( 'mwoauthmanageconsumers-search-name' )->escaped(),
								[],
								[ 'name' => $s ]
							);
							return htmlspecialchars( $s ) . ' ' .
								   $this->msg( 'parentheses' )->rawParams( $link )->escaped();
						} ) ),
						'mwoauth-consumer-version' => $cmrAc->getVersion(),
						'mwoauth-consumer-user' => new HtmlSnippet( $ownerLink ),
						'mwoauth-consumer-description' => $cmrAc->getDescription(),
						'mwoauth-consumer-owner-only-label' => $ownerOnly ?
							$this->msg( 'mwoauth-consumer-owner-only', $owner ) : null,
						'mwoauth-consumer-callbackurl' => $ownerOnly ?
							null : $cmrAc->getCallbackUrl(),
						'mwoauth-consumer-callbackisprefix' => $ownerOnly ?
							null : ( $cmrAc->getCallbackIsPrefix() ?
								$this->msg( 'htmlform-yes' ) : $this->msg( 'htmlform-no' ) ),
						'mwoauth-consumer-grantsneeded' => $cmrAc->get( 'grants',
							function ( $grants ) use ( $lang ) {
								return $lang->semicolonList( \MWGrants::grantNames( $grants, $lang ) );
							} ),
						'mwoauth-consumer-email' => $cmrAc->getEmail(),
						'mwoauth-consumer-wiki' => $cmrAc->getWiki(),
						'mwoauth-consumer-restrictions-json' => $restrictions instanceof \MWRestrictions ?
							$restrictions->toJson( true ) : $restrictions,
						'mwoauth-consumer-rsakey' => $cmrAc->getRsaKey(),
					], $this->getContext() ),
				],
				'action' => [
					'type' => 'radio',
					'label-message' => 'mwoauthmanageconsumers-action',
					'required' => true,
					'options' => $opts,
					'default' => '', // no validate on GET
				],
				'reason' => [
					'type' => 'text',
					'label-message' => 'mwoauthmanageconsumers-reason',
					'required' => true,
				],
				'consumerKey' => [
					'type' => 'hidden',
					'default' => $cmrAc->getConsumerKey(),
				],
				'changeToken' => [
					'type' => 'hidden',
					'default' => $cmrAc->getDAO()->getChangeToken( $this->getContext() ),
				],
			] ),
			$this->getContext()
		);
		$form->setSubmitCallback(
			function ( array $data, \IContextSource $context ) use ( $control ) {
				$data['suppress'] = 0;
				if ( $data['action'] === 'dsuppress' ) {
					$data = [ 'action' => 'disable', 'suppress' => 1 ] + $data;
				} elseif ( $data['action'] === 'rsuppress' ) {
					$data = [ 'action' => 'reject', 'suppress' => 1 ] + $data;
				}
				$control->setInputParameters( $data );
				return $control->submit();
			}
		);

		$form->setWrapperLegendMsg( 'mwoauthmanageconsumers-confirm-legend' );
		$form->setSubmitTextMsg( 'mwoauthmanageconsumers-confirm-submit' );
		$form->addPreText(
			$this->msg( 'mwoauthmanageconsumers-confirm-text' )->parseAsBlock() );

		$status = $form->show();
		if ( $status instanceof \Status && $status->isOK() ) {
			/** @var MWOAuthConsumer $cmr */
			$cmr = $status->value['result'];
			$oldStageKey = MWOAuthConsumer::$stageNames[$startingStage];
			$newStageKey = MWOAuthConsumer::$stageNames[$cmr->getStage()];
			// Messages: mwoauthmanageconsumers-success-approved, mwoauthmanageconsumers-success-rejected,
			// mwoauthmanageconsumers-success-disabled
			$this->getOutput()->addWikiMsg( "mwoauthmanageconsumers-success-$newStageKey" );
			$returnTo = \Title::newFromText( 'Special:OAuthManageConsumers/' . $oldStageKey );
			$this->getOutput()->addReturnTo( $returnTo, [],
				// Messages: mwoauthmanageconsumers-linkproposed,
				// mwoauthmanageconsumers-linkrejected, mwoauthmanageconsumers-linkexpired,
				// mwoauthmanageconsumers-linkapproved, mwoauthmanageconsumers-linkdisabled
				$this->msg( 'mwoauthmanageconsumers-link' . $oldStageKey )->text() );
		} else {
			$out = $this->getOutput();
			// Show all of the status updates
			$logPage = new \LogPage( 'mwoauthconsumer' );
			$out->addHTML( \Xml::element( 'h2', null, $logPage->getName()->text() ) );
			\LogEventsList::showLogExtract( $out, 'mwoauthconsumer', '', '', [
				'conds' => [
					'ls_field' => 'OAuthConsumer',
					'ls_value' => $cmrAc->getConsumerKey(),
				],
				'flags' => \LogEventsList::NO_EXTRA_USER_LINKS,
			] );
		}
	}

	/**
	 * Show a paged list of consumers with links to details
	 */
	protected function showConsumerList() {
		$pager = new MWOAuthManageConsumersPager( $this, [], $this->stage );
		if ( $pager->getNumRows() ) {
			$this->getOutput()->addHTML( $pager->getNavigationBar() );
			$this->getOutput()->addHTML( $pager->getBody() );
			$this->getOutput()->addHTML( $pager->getNavigationBar() );
		} else {
			// Messages: mwoauthmanageconsumers-none-proposed, mwoauthmanageconsumers-none-rejected,
			// mwoauthmanageconsumers-none-expired, mwoauthmanageconsumers-none-approved,
			// mwoauthmanageconsumers-none-disabled
			$this->getOutput()->addWikiMsg( "mwoauthmanageconsumers-none-{$this->stageKey}" );
		}
		# Every 30th view, prune old deleted items
		if ( 0 == mt_rand( 0, 29 ) ) {
			MWOAuthUtils::runAutoMaintenance( MWOAuthUtils::getCentralDB( DB_MASTER ) );
		}
	}

	/**
	 * @param DBConnRef $db
	 * @param \stdClass $row
	 * @return string
	 */
	public function formatRow( DBConnRef $db, $row ) {
		$cmrAc = MWOAuthConsumerAccessControl::wrap(
			MWOAuthConsumer::newFromRow( $db, $row ), $this->getContext() );

		$cmrKey = $cmrAc->getConsumerKey();
		$stageKey = MWOAuthConsumer::$stageNames[$cmrAc->getStage()];

		$link = \Linker::linkKnown(
			$this->getPageTitle( $cmrKey ),
			$this->msg( 'mwoauthmanageconsumers-review' )->escaped()
		);

		$time = $this->getLanguage()->timeanddate(
			wfTimestamp( TS_MW, $cmrAc->getRegistration() ), true );

		$encStageKey = htmlspecialchars( $stageKey ); // sanity
		$r = "<li class='mw-mwoauthmanageconsumers-{$encStageKey}'>";

		$r .= $time . " (<strong>{$link}</strong>)";

		// Show last log entry (@TODO: title namespace?)
		// @TODO: inject DB
		$logHtml = '';
		\LogEventsList::showLogExtract( $logHtml, 'mwoauthconsumer', '', '', [
			'action' => MWOAuthConsumer::$stageActionNames[$cmrAc->getStage()],
			'conds'  => [
				'ls_field' => 'OAuthConsumer',
				'ls_value' => $cmrAc->getConsumerKey(),
			],
			'lim'    => 1,
			'flags'  => \LogEventsList::NO_EXTRA_USER_LINKS,
		] );

		$lang = $this->getLanguage();
		$data = [
			'mwoauthmanageconsumers-name' => $cmrAc->escapeForHtml( $cmrAc->getNameAndVersion() ),
			'mwoauthmanageconsumers-user' => $cmrAc->escapeForHtml( $cmrAc->getUserName() ),
			'mwoauthmanageconsumers-description' => $cmrAc->escapeForHtml(
				$cmrAc->get( 'description', function ( $s ) use ( $lang ) {
					return $lang->truncateForVisual( $s, 10024 );
				} )
			),
			'mwoauthmanageconsumers-email' => $cmrAc->escapeForHtml( $cmrAc->getEmail() ),
			'mwoauthmanageconsumers-consumerkey' => $cmrAc->escapeForHtml( $cmrAc->getConsumerKey() ),
			'mwoauthmanageconsumers-lastchange' => $logHtml,
		];

		$r .= "<table class='mw-mwoauthmanageconsumers-body' " .
			"cellspacing='1' cellpadding='3' border='1' width='100%'>";
		foreach ( $data as $msg => $encValue ) {
			$r .= '<tr>' .
				'<td><strong>' . $this->msg( $msg )->escaped() . '</strong></td>' .
				'<td width=\'90%\'>' . $encValue . '</td>' .
				'</tr>';
		}
		$r .= '</table>';

		$r .= '</li>';

		return $r;
	}

	protected function getGroupName() {
		return 'users';
	}
}
