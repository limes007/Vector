<?php

namespace Vector;

use Config;
use ExtensionRegistry;
use HTMLForm;
use MediaWiki\MediaWikiServices;
use OutputPage;
use ResourceLoaderContext;
use Skin;
use SkinTemplate;
use SkinVector;
use User;
use Vector\HTMLForm\Fields\HTMLLegacySkinVersionField;

/**
 * Presentation hook handlers for Vector skin.
 *
 * Hook handler method names should be in the form of:
 *	on<HookName>()
 * @package Vector
 * @internal
 */
class Hooks {
	/**
	 * Passes config variables to Vector (modern) ResourceLoader module.
	 * @param ResourceLoaderContext $context
	 * @param Config $config
	 * @return array
	 */
	public static function getVectorResourceLoaderConfig(
		ResourceLoaderContext $context,
		Config $config
	) {
		return [
			'wgVectorUseCoreSearch' => $config->get( 'VectorUseCoreSearch' ),
		];
	}

	/**
	 * BeforePageDisplayMobile hook handler
	 *
	 * Make Legacy Vector responsive when $wgVectorResponsive = true
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/BeforePageDisplay
	 * @param OutputPage $out
	 * @param SkinTemplate $sk
	 */
	public static function onBeforePageDisplay( OutputPage $out, $sk ) {
		if ( !$sk instanceof SkinVector ) {
			return;
		}

		$mobile = false;
		if ( ExtensionRegistry::getInstance()->isLoaded( 'MobileFrontend' ) ) {

			$mobFrontContext = MediaWikiServices::getInstance()->getService( 'MobileFrontend.Context' );
			$mobile = $mobFrontContext->shouldDisplayMobileView();
		}

		if (
			self::isSkinVersionLegacy()
			&& ( $mobile || $sk->getConfig()->get( 'VectorResponsive' ) ) ) {
			$out->addMeta( 'viewport', 'width=device-width, initial-scale=1' );
			$out->addModuleStyles( 'skins.vector.styles.responsive' );
		}
	}

	/**
	 * SkinPageReadyConfig hook handler
	 *
	 * Replace searchModule provided by skin.
	 *
	 * @since 1.35
	 * @param ResourceLoaderContext $context
	 * @param mixed[] &$config Associative array of configurable options
	 * @return void This hook must not abort, it must return no value
	 */
	public static function onSkinPageReadyConfig(
		ResourceLoaderContext $context,
		array &$config
	) {
		// It's better to exit before any additional check
		if ( $context->getSkin() !== 'vector' ) {
			return;
		}

		// Tell the `mediawiki.page.ready` module not to wire up search.
		// This allows us to use $wgVectorUseCoreSearch to decide to load
		// the historic jquery autocomplete search or the new Vue implementation.
		// ResourceLoaderContext has no knowledge of legacy / modern Vector
		// and from its point of view they are the same thing.
		// Please see the modules `skins.vector.js` and `skins.vector.legacy.js`
		// for the wire up of search.
		// The related method self::getVectorResourceLoaderConfig handles which
		// search to load.
		$config['search'] = false;
	}

	/**
	 * Add icon class to an existing navigation item inside a menu hook.
	 * See self::onSkinTemplateNavigation.
	 * @param array $item
	 * @return array
	 */
	private static function navigationLinkToIcon( array $item ) {
		if ( !isset( $item['class'] ) ) {
			$item['class'] = '';
		}
		$item['class'] = rtrim( 'icon ' . $item['class'], ' ' );
		return $item;
	}

	/**
	 * Upgrades Vector's watch action to a watchstar.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinTemplateNavigation
	 * @param SkinTemplate $sk
	 * @param array &$content_navigation
	 */
	public static function onSkinTemplateNavigation( $sk, &$content_navigation ) {
		$title = $sk->getRelevantTitle();
		if (
			$sk->getConfig()->get( 'VectorUseIconWatch' ) &&
			$sk->getSkinName() === 'vector' &&
			$title && $title->canExist()
		) {
			$key = null;
			if ( isset( $content_navigation['actions']['watch'] ) ) {
				$key = 'watch';
			}
			if ( isset( $content_navigation['actions']['unwatch'] ) ) {
				$key = 'unwatch';
			}

			// Promote watch link from actions to views and add an icon
			if ( $key !== null ) {
				$content_navigation['views'][$key] = self::navigationLinkToIcon(
					$content_navigation['actions'][$key]
				);
				unset( $content_navigation['actions'][$key] );
			}
		}
	}

	/**
	 * Add Vector preferences to the user's Special:Preferences page directly underneath skins.
	 *
	 * @param User $user User whose preferences are being modified.
	 * @param array[] &$prefs Preferences description array, to be fed to a HTMLForm object.
	 */
	public static function onGetPreferences( User $user, array &$prefs ) {
		if ( !self::getConfig( Constants::CONFIG_KEY_SHOW_SKIN_PREFERENCES ) ) {
			// Do not add Vector skin specific preferences.
			return;
		}

		// Preferences to add.
		$vectorPrefs = [
			Constants::PREF_KEY_SKIN_VERSION => [
				'class' => HTMLLegacySkinVersionField::class,
				// The checkbox title.
				'label-message' => 'prefs-vector-enable-vector-1-label',
				// Show a little informational snippet underneath the checkbox.
				'help-message' => 'prefs-vector-enable-vector-1-help',
				// The tab location and title of the section to insert the checkbox. The bit after the slash
				// indicates that a prefs-skin-prefs string will be provided.
				'section' => 'rendering/skin/skin-prefs',
				'default' => self::isSkinVersionLegacy(),
				// Only show this section when the Vector skin is checked. The JavaScript client also uses
				// this state to determine whether to show or hide the whole section.
				'hide-if' => [ '!==', 'wpskin', Constants::SKIN_NAME ],
			],
			Constants::PREF_KEY_SIDEBAR_VISIBLE => [
				'type' => 'api',
				'default' => self::getConfig( Constants::CONFIG_KEY_DEFAULT_SIDEBAR_VISIBLE_FOR_AUTHORISED_USER )
			],
		];

		// Seek the skin preference section to add Vector preferences just below it.
		$skinSectionIndex = array_search( 'skin', array_keys( $prefs ) );
		if ( $skinSectionIndex !== false ) {
			// Skin preference section found. Inject Vector skin-specific preferences just below it.
			// This pattern can be found in Popups too. See T246162.
			$vectorSectionIndex = $skinSectionIndex + 1;
			$prefs = array_slice( $prefs, 0, $vectorSectionIndex, true )
				+ $vectorPrefs
				+ array_slice( $prefs, $vectorSectionIndex, null, true );
		} else {
			// Skin preference section not found. Just append Vector skin-specific preferences.
			$prefs += $vectorPrefs;
		}
	}

	/**
	 * Hook executed on user's Special:Preferences form save. This is used to convert the boolean
	 * presentation of skin version to a version string. That is, a single preference change by the
	 * user may trigger two writes: a boolean followed by a string.
	 *
	 * @param array $formData Form data submitted by user
	 * @param HTMLForm $form A preferences form
	 * @param User $user Logged-in user
	 * @param bool &$result Variable defining is form save successful
	 * @param array $oldPreferences
	 */
	public static function onPreferencesFormPreSave(
		array $formData,
		HTMLForm $form,
		User $user,
		&$result,
		$oldPreferences
	) {
		$isVectorEnabled = ( $formData[ 'skin' ] ?? '' ) === Constants::SKIN_NAME;

		if ( !$isVectorEnabled && array_key_exists( Constants::PREF_KEY_SKIN_VERSION, $oldPreferences ) ) {
			// The setting was cleared. However, this is likely because a different skin was chosen and
			// the skin version preference was hidden.
			$user->setOption(
				Constants::PREF_KEY_SKIN_VERSION,
				$oldPreferences[ Constants::PREF_KEY_SKIN_VERSION ]
			);
		}
	}

	/**
	 * Called one time when initializing a users preferences for a newly created account.
	 *
	 * @param User $user Newly created user object.
	 * @param bool $isAutoCreated
	 */
	public static function onLocalUserCreated( User $user, $isAutoCreated ) {
		$default = self::getConfig( Constants::CONFIG_KEY_DEFAULT_SKIN_VERSION_FOR_NEW_ACCOUNTS );
		// Permanently set the default preference. The user can later change this preference, however,
		// self::onLocalUserCreated() will not be executed for that account again.
		$user->setOption( Constants::PREF_KEY_SKIN_VERSION, $default );
	}

	/**
	 * Called when OutputPage::headElement is creating the body tag to allow skins
	 * and extensions to add attributes they might need to the body of the page.
	 *
	 * @param OutputPage $out
	 * @param Skin $sk
	 * @param string[] &$bodyAttrs
	 */
	public static function onOutputPageBodyAttributes( OutputPage $out, Skin $sk, &$bodyAttrs ) {
		if ( !$sk instanceof SkinVector ) {
			return;
		}

		// As of 2020/08/13, this CSS class is referred to by the following deployed extensions:
		//
		// - VisualEditor
		// - CodeMirror
		// - WikimediaEvents
		//
		// See https://codesearch.wmcloud.org/deployed/?q=skin-vector-legacy for an up-to-date
		// list.
		if ( self::isSkinVersionLegacy() ) {
			$bodyAttrs['class'] .= ' skin-vector-legacy';

			return;
		}

		$bodyAttrs['class'] .= ' skin-vector-max-width';

		// As of 2020/08/12, the following CSS classes are referred to by the following deployed
		// extensions:
		//
		// - WikimediaEvents
		//
		// See https://codesearch.wmcloud.org/deployed/?q=skin-vector-search- for an up-to-date
		// list.

		$featureManager = VectorServices::getFeatureManager();
		if ( $featureManager->isFeatureEnabled( Constants::FEATURE_SEARCH_IN_HEADER ) ) {
			$bodyAttrs['class'] .= ' skin-vector-search-header';
		} else {
			$bodyAttrs['class'] .= ' skin-vector-search-header-legacy';
		}
	}

	/**
	 * NOTE: Please use ResourceLoaderGetConfigVars hook instead if possible
	 * for adding config to the page.
	 * Adds config variables to JS that depend on current page/request.
	 *
	 * Adds a config flag that can disable saving the VectorSidebarVisible
	 * user preference when the sidebar menu icon is clicked.
	 *
	 * @param array &$vars Array of variables to be added into the output.
	 * @param OutputPage $out OutputPage instance calling the hook
	 */
	public static function onMakeGlobalVariablesScript( &$vars, OutputPage $out ) {
		if ( !$out->getSkin() instanceof SkinVector ) {
			return;
		}

		$user = $out->getUser();

		if ( $user->isLoggedIn() && self::isSkinVersionLegacy() ) {
			$vars[ 'wgVectorDisableSidebarPersistence' ] =
				self::getConfig(
					Constants::CONFIG_KEY_DISABLE_SIDEBAR_PERSISTENCE
				);
		}
	}

	/**
	 * Get a configuration variable such as `Constants::CONFIG_KEY_SHOW_SKIN_PREFERENCES`.
	 *
	 * @param string $name Name of configuration option.
	 * @return mixed Value configured.
	 * @throws \ConfigException
	 */
	private static function getConfig( $name ) {
		return self::getServiceConfig()->get( $name );
	}

	/**
	 * @return \Config
	 */
	private static function getServiceConfig() {
		return MediaWikiServices::getInstance()->getService( Constants::SERVICE_CONFIG );
	}

	/**
	 * Gets whether the current skin version is the legacy version.
	 *
	 * @see VectorServices::getFeatureManager
	 *
	 * @return bool
	 */
	private static function isSkinVersionLegacy(): bool {
		return !VectorServices::getFeatureManager()->isFeatureEnabled( Constants::FEATURE_LATEST_SKIN );
	}
}
