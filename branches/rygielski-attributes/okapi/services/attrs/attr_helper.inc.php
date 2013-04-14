<?php

namespace okapi\services\attrs;

use Exception;
use ErrorException;
use okapi\Okapi;
use okapi\Settings;
use okapi\Cache;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiServiceRunner;
use okapi\OkapiInternalRequest;
use SimpleXMLElement;


class AttrHelper
{
	//private static $SOURCE_URL = "http://opencaching-api.googlecode.com/svn/trunk/etc/attributes.xml";
	private static $SOURCE_URL = "http://opencaching-api.googlecode.com/svn/branches/rygielski-attributes/etc/attributes.xml";
	private static $REFRESH_INTERVAL = 86400;
	private static $CACHE_KEY = 'attrs/attrlist/2';
	private static $attr_dict = null;
	private static $last_refreshed = null;

	/**
	 * Forces the download of the new attributes from Google Code.
	 */
	private static function refresh_now()
	{
		try
		{
			$opts = array(
				'http' => array(
					'method' => "GET",
					'timeout' => 5.0
				)
			);
			$context = stream_context_create($opts);
			$xml = file_get_contents(self::$SOURCE_URL, false, $context);
		}
		catch (ErrorException $e)
		{
			# Google failed on us. We won't update the cached attributes.
			return;
		}

		self::refresh_from_file($xml);
	}

	/**
	 * Refreshed all attributes from the given file. Usually, this file is
	 * downloaded from Google Code (using refresh_now).
	 */
	public static function refresh_from_file($xml)
	{
		/* attribute.xml file defines attribute relationships between various OC
		 * installations. Each installation uses its own internal ID schema.
		 * We will temporarily assume that "oc.pl" codebranch uses OCPL's schema
		 * and "oc.de" codebranch - OCDE's. This is wrong for OCUS and OCORGUK
		 * nodes, which use "oc.pl" codebranch, but have a schema of their own
		 * WRTODO. */

		if (Settings::get('OC_BRANCH') == 'oc.pl')
			$my_schema = "OCPL";
		else
			$my_schema = "OCDE";

		$doc = simplexml_load_string($xml);
		$cachedvalue = array(
			'attr_dict' => array(),
			'last_refreshed' => time(),
		);
		foreach ($doc->attr as $attrnode)
		{
			$attr = array(
				'id' => (string)$attrnode['okapi_attr_id'],
				'gs_equivs' => array(),
				'internal_ids' => array(),
				'names' => array(),
				'descriptions' => array(),
				'search_inc_captions' => array(),
				'search_exc_captions' => array(),
			);
			foreach ($attrnode->groundspeak as $gsnode)
			{
				$attr['gs_equivs'][] = array(
					'id' => (int)$gsnode['id'],
					'inc' => in_array((string)$gsnode['inc'], array("true", "1")) ? 1 : 0,
					'name' => (string)$gsnode['name']
				);
			}
			foreach ($attrnode->opencaching as $ocnode)
			{
				if ((string)$ocnode['schema'] == $my_schema)
				{
					$attr['internal_ids'][] = (int)$ocnode['id'];
				}
			}
			foreach ($attrnode->lang as $langnode)
			{
				$lang = (string)$langnode['id'];
				foreach ($langnode->name as $namenode)
				{
					$attr['names'][$lang] = (string)$namenode;
				}
				foreach ($langnode->search as $searchnode)
				{
					foreach ($searchnode->inc as $captionnode)
						$attr['search_inc_captions'][$lang] = (string)$captionnode;
					foreach ($searchnode->exc as $captionnode)
						$attr['search_exc_captions'][$lang] = (string)$captionnode;
				}
				foreach ($langnode->desc as $descnode)
				{
					$xml = $descnode->asxml(); /* contains "<desc>" and "</desc>" */
					$innerxml = preg_replace("/(^[^>]+>)|(<[^<]+$)/us", "", $xml);
					$attr['descriptions'][$lang] = self::cleanup_string($innerxml);
				}
			}
			$cachedvalue['attr_dict'][$attr['id']] = $attr;
		}

		# Cache it for a month (just in case, usually it will be refreshed every day).

		Cache::set(self::$CACHE_KEY, $cachedvalue, 30*86400);
		self::$attr_dict = $cachedvalue['attr_dict'];
		self::$last_refreshed = $cachedvalue['last_refreshed'];
	}

	/**
	 * Initialize all the internal attributes (if not yet initialized). This
	 * loads attribute values from the cache. If they are not present in the cache,
	 * it won't download them from Google Code, it will initialize them as empty!
	 */
	private static function init_from_cache()
	{
		if (self::$attr_dict !== null)
		{
			/* Already initialized. */
			return;
		}
		$cachedvalue = Cache::get(self::$CACHE_KEY);
		if ($cachedvalue === null)
		{
			$cachedvalue = array(
				'attr_dict' => array(),
				'last_refreshed' => 0,
			);
		}
		self::$attr_dict = $cachedvalue['attr_dict'];
		self::$last_refreshed = $cachedvalue['last_refreshed'];
	}

	/**
	 * Check if the cached attribute values might be stale. If they were not
	 * refreshed in a while, perform the refresh from Google Code. (This might
	 * take a couple of seconds, it should be done via a cronjob.)
	 */
	public static function refresh_if_stale()
	{
		self::init_from_cache();
		if (self::$last_refreshed < time() - self::$REFRESH_INTERVAL)
			self::refresh_now();
		if (self::$last_refreshed < time() - 3 * self::$REFRESH_INTERVAL)
		{
			Okapi::mail_admins(
				"OKAPI was unable to refresh attributes",
				"OKAPI periodically refreshes all cache attributes from the list\n".
				"kept in global repository. OKAPI tried to contact the repository,\n".
				"but it failed. Your list of attributes might be stale.\n\n".
				"You should probably update OKAPI or contact OKAPI developers."
			);
		}
	}

	/**
	 * Return a dictionary of all attributes. The format is the same as in the "attributes"
	 * key returned by the "services/attrs/attrlist" method.
	 */
	public static function get_attrdict()
	{
		self::init_from_cache();
		return self::$attr_dict;
	}

	/** "\n\t\tBla   blabla\n\t\t<b>bla</b>bla.\n\t" => "Bla blabla <b>bla</b>bla." */
	private static function cleanup_string($s)
	{
		return preg_replace('/(^\s+)|(\s+$)/us', "", preg_replace('/\s+/us', " ", $s));
	}
}
