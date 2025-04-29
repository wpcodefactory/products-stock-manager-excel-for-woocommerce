<?php

namespace codecabin;

if(!is_admin())
	return;

global $pagenow;

if($pagenow != "plugins.php")
	return;

if(defined('CODECABIN_DEACTIVATE_FEEDBACK_FORM_INCLUDED'))
	return;
define('CODECABIN_DEACTIVATE_FEEDBACK_FORM_INCLUDED', true);

add_action('admin_enqueue_scripts', function() {
	
	// Enqueue scripts
	wp_enqueue_script('remodal', plugin_dir_url(__FILE__) . 'remodal.min.js');
	wp_enqueue_style('remodal', plugin_dir_url(__FILE__) . 'remodal.css');
	wp_enqueue_style('remodal-default-theme', plugin_dir_url(__FILE__) . 'remodal-default-theme.css');
	
	wp_enqueue_script('codecabin-deactivate-feedback-form', plugin_dir_url(__FILE__) . 'deactivate-feedback-form.js?v=xssgs');
	wp_enqueue_style('codecabin-deactivate-feedback-form', plugin_dir_url(__FILE__) . 'deactivate-feedback-form.css');
	
	// Localized strings
	wp_localize_script('codecabin-deactivate-feedback-form', 'codecabin_deactivate_feedback_form_strings', array(
		'quick_feedback'			=> __('We are sorry to see you go...', 'products-stock-manager-excel'),
		'foreword'					=> __('If you would be kind enough, please tell us why you\'re deactivating?', 'products-stock-manager-excel'),
		'better_plugins_name'		=> __('Please tell us which plugin?', 'products-stock-manager-excel'),
		'please_tell_us'			=> __('Please tell us the reason so we can improve the plugin', 'products-stock-manager-excel'),
		'do_not_attach_email'		=> __('Do not send my e-mail address with this feedback', 'products-stock-manager-excel'),
		
		'brief_description'			=> __('Please give us any feedback that could help us improve', 'products-stock-manager-excel'),
		
		'cancel'					=> __('Cancel', 'products-stock-manager-excel'),
		'skip_and_deactivate'		=> __('Skip &amp; Deactivate', 'products-stock-manager-excel'),
		'submit_and_deactivate'		=> __('Submit &amp; Deactivate', 'products-stock-manager-excel'),
		'please_wait'				=> __('Please wait', 'products-stock-manager-excel'),
		'thank_you'					=> __('Thank you!', 'products-stock-manager-excel')
	));
	
	// Plugins
	$plugins = apply_filters('codecabin_deactivate_feedback_form_plugins', array());
	
	// Reasons
	$defaultReasons = array(
		'suddenly-stopped-working'	=> __('The plugin suddenly stopped working', 'products-stock-manager-excel'),
		'plugin-broke-site'			=> __('The plugin broke my site', 'products-stock-manager-excel'),
		'no-longer-needed'			=> __('I don\'t need this plugin any more', 'products-stock-manager-excel'),
		'found-better-plugin'		=> __('I found a better plugin', 'products-stock-manager-excel'),
		'temporary-deactivation'	=> __('It\'s a temporary deactivation, I\'m troubleshooting', 'products-stock-manager-excel'),
		'other'						=> __('Other', 'products-stock-manager-excel')
	);
	
	foreach($plugins as $plugin)
	{
		$plugin->reasons = apply_filters('codecabin_deactivate_feedback_form_reasons', $defaultReasons, $plugin);
	}
	
	// Send plugin data
	wp_localize_script('codecabin-deactivate-feedback-form', 'codecabin_deactivate_feedback_form_plugins', $plugins);
});

/**
 * Hook for adding plugins, pass an array of objects in the following format:
 *  'slug'		=> 'plugin-slug'
 *  'version'	=> 'plugin-version'
 * @return array The plugins in the format described above
 */
add_filter('codecabin_deactivate_feedback_form_plugins', function($plugins) {
	return $plugins;
});

