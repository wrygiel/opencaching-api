<?php

namespace okapi\services\caches\save_user_coords;

use okapi\core\Db;
use okapi\core\Exception\ParamMissing;
use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
use okapi\core\OkapiServiceRunner;
use okapi\core\Request\OkapiInternalRequest;
use okapi\core\Request\OkapiRequest;
use okapi\Settings;

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 3
        );
    }

    public static function call(OkapiRequest $request)
    {

        $user_coords = $request->get_parameter('user_coords');
        if ($user_coords == null)
            throw new ParamMissing('user_coords');
        $parts = explode('|', $user_coords);
        if (count($parts) != 2)
            throw new InvalidParam('user_coords', "Expecting 2 pipe-separated parts, got ".count($parts).".");
        foreach ($parts as &$part_ref)
        {
            if (!preg_match("/^-?[0-9]+(\.?[0-9]*)$/", $part_ref))
                throw new InvalidParam('user_coords', "'$part_ref' is not a valid float number.");
            $part_ref = floatval($part_ref);
        }
        list($latitude, $longitude) = $parts;

        # Verify cache_code

        $cache_code = $request->get_parameter('cache_code');
        if ($cache_code == null)
            throw new ParamMissing('cache_code');
        $geocache = OkapiServiceRunner::call(
            'services/caches/geocache',
            new OkapiInternalRequest($request->consumer, $request->token, array(
                'cache_code' => $cache_code,
                'fields' => 'internal_id'
            ))
        );
        $cache_id = $geocache['internal_id'];

        self::update_coordinates($cache_id, $request->token->user_id, $latitude, $longitude);

        $result = array(
            'success' => true
        );
        return Okapi::formatted_response($request, $result);
    }

    private static function update_coordinates($cache_id, $user_id, $latitude, $longitude)
    {
        if (Settings::get('OC_BRANCH') == 'oc.de')
        {

            /* See:
             *
             * - https://github.com/OpencachingDeutschland/oc-server3/tree/development/htdocs/src/Oc/Libse/CacheNote
             * - https://www.opencaching.de/okapi/devel/dbstruct
             */

            $rs = Db::query("
                select max(id) as id
                from coordinates
                where
                    type = 2  -- personal note
                    and cache_id = '".Db::escape_string($cache_id)."'
                    and user_id = '".Db::escape_string($user_id)."'
            ");
            $id = null;
            if($row = Db::fetch_assoc($rs)) {
                $id = $row['id'];
            }
            if ($id == null) {
                Db::query("
                    insert into coordinates (
                        type, latitude, longitude, cache_id, user_id, description
                    ) values (
                        2,
                        '".Db::escape_string($latitude)."',
                        '".Db::escape_string($longitude)."',
                        '".Db::escape_string($cache_id)."',
                        '".Db::escape_string($user_id)."',
                        '".Db::escape_string("")."'
                    )
                ");
            } else {
                Db::query("
                    update coordinates
                    set latitude  = '".Db::escape_string($latitude)."',
                        longitude = '".Db::escape_string($longitude)."'
                    where
                        id = '".Db::escape_string($id)."'
                        and type = 2
                ");
            }
        }
        else # oc.pl branch
        {
            $rs = Db::query("
                select max(id) as id
                from cache_mod_cords
                where
                    cache_id    = '".Db::escape_string($cache_id)."'
                    and user_id = '".Db::escape_string($user_id)."'
            ");
            $id = null;
            if($row = Db::fetch_assoc($rs)) {
                $id = $row['id'];
            }
            if ($id == null) {
                Db::query("
                    insert into cache_mod_cords (
                        cache_id, user_id, latitude, longitude
                    ) values (
                        '".Db::escape_string($cache_id)."',
                        '".Db::escape_string($user_id)."',
                        '".Db::escape_string($latitude)."',
                        '".Db::escape_string($longitude)."'
                    )
                ");
            } else {
                Db::query("
                    update cache_mod_cords
                    set latitude  = '".Db::escape_string($latitude)."',
                        longitude = '".Db::escape_string($longitude)."'
                    where
                        id = '".Db::escape_string($id)."'
                ");
            }
        }
    }
}
