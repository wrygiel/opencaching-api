<?php

namespace okapi\services\apisrv\installation;

use okapi\core\Db;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;
use okapi\Settings;

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 0
        );
    }

    public static function call(OkapiRequest $request)
    {
        $result = array();
        $result['site_url'] = Settings::get('SITE_URL');
        $result['okapi_base_url'] = Okapi::get_recommended_base_url();
        $result['okapi_base_urls'] = Okapi::get_allowed_base_urls();
        $result['site_name'] = Okapi::get_normalized_site_name();
        $result['okapi_version_number'] = Okapi::getVersionNumber();
        $result['okapi_revision'] = Okapi::getVersionNumber(); /* Important for backward-compatibility! */
        $result['okapi_git_revision'] = Okapi::getGitRevision();
        $result['registration_url'] = Settings::get('REGISTRATION_URL');
        $result['mobile_registration_url'] = Settings::get('MOBILE_REGISTRATION_URL');
        $result['image_max_upload_size'] = Settings::get('IMAGE_MAX_UPLOAD_SIZE');
        $result['image_rcmd_max_pixels'] = Settings::get('IMAGE_MAX_PIXEL_COUNT');
        $result['has_image_positions'] = Settings::get('OC_BRANCH') == 'oc.de';
        $result['has_ratings'] = Settings::get('OC_BRANCH') == 'oc.pl';
        $result['geocache_passwd_max_length'] = Db::field_length('caches', 'logpw');
        if (Settings::get('OC_BRANCH') == 'oc.de') {
            $result['has_draft_logs'] = true;
            $result['has_lists']      = true;
            $result['cache_types']    = self::getCacheTypes();
            $result['log_types']      = self::getLogTypes();

        }

        return Okapi::formatted_response($request, $result);
    }

    private static function getCacheTypes() {
        $rs = Db::query("
           SELECT name
            FROM cache_type;
        ");
        $cache_types = [];
        while ($row = Db::fetch_assoc($rs)) {
            $cache_types[] = $row['name'];
        }
        return $cache_types;
    }

    private static function getLogTypes() {
        $rs = Db::query("
           SELECT name
            FROM log_types;
        ");
        $log_types = [];
        while ($row = Db::fetch_assoc($rs)) {
            $log_types[] = $row['name'];
        }
        return $log_types;
    }
}
