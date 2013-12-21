<?php

namespace okapi\services\apiref\method;

use Exception;
use okapi\Okapi;
use okapi\Settings;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiServiceRunner;
use okapi\OkapiInternalRequest;
use okapi\OkapiInternalConsumer;

class WebService
{
	public static function options()
	{
		return array(
			'min_auth_level' => 0
		);
	}

	private static function arg_desc($arg_node)
	{
		$attrs = $arg_node->attributes();
		return array(
			'name' => (string)$attrs['name'],
			'is_required' => $arg_node->getName() == 'req',
			'is_deprecated' => (isset($attrs['class']) && (strpos($attrs['class'], 'deprecated') !== false)),
			'class' => 'public',
			'description' =>
				(isset($attrs['default']) ? ("<p>Default value: <b>".$attrs['default']."</b></p>") : "").
				self::get_inner_xml($arg_node),

		);
	}

	private static function get_inner_xml($node)
	{
		/* Fetch as <some-node>content</some-node>, extract content. */

		$s = $node->asXML();
		$start = strpos($s, ">") + 1;
		$length = strlen($s) - $start - (3 + strlen($node->getName()));
		$s = substr($s, $start, $length);

		/* Find and replace %okapi:plugins%. */

		$s = preg_replace_callback("~%OKAPI:([a-z:]+)%~", array("self", "plugin_callback"), $s);

		return $s;
	}

	public static function plugin_callback($matches)
	{
		$input = $matches[1];
		$arr = explode(":", $input);
		$plugin_name = $arr[0];

		switch ($plugin_name) {
			case 'docurl':
				$fragment = $arr[1];
				return Settings::get('SITE_URL')."okapi/introduction.html#".$fragment;
			default:
				throw new Exception("Unknown plugin: ".$input);
		}
	}

	public static function call(OkapiRequest $request)
	{
		$methodname = $request->get_parameter('name');
		if (!$methodname)
			throw new ParamMissing('name');
		if (!preg_match("#^services/[0-9a-z_/]*$#", $methodname))
			throw new InvalidParam('name');
		if (!OkapiServiceRunner::exists($methodname))
			throw new InvalidParam('name', "Method does not exist: '$methodname'.");
		$options = OkapiServiceRunner::options($methodname);
		if (!isset($options['min_auth_level']))
			throw new Exception("Method $methodname is missing a required 'min_auth_level' option!");
		$docs = simplexml_load_string(OkapiServiceRunner::docs($methodname));
		$exploded = explode("/", $methodname);
		$result = array(
			'name' => $methodname,
			'short_name' => end($exploded),
			'ref_url' => Settings::get('SITE_URL')."okapi/$methodname.html",
			'auth_options' => array(
				'min_auth_level' => $options['min_auth_level'],
				'oauth_consumer' => $options['min_auth_level'] >= 2,
				'oauth_token' => $options['min_auth_level'] >= 3,
			)
		);
		if (!$docs->brief)
			throw new Exception("Missing <brief> element in the $methodname.xml file.");
		if ($docs->brief != self::get_inner_xml($docs->brief))
			throw new Exception("The <brief> element may not contain HTML markup ($methodname.xml).");
		if (strlen($docs->brief) > 80)
			throw new Exception("The <brief> description may not be longer than 80 characters ($methodname.xml).");
		if (strpos($docs->brief, "\n") !== false)
			throw new Exception("The <brief> element may not contain new-lines ($methodname.xml).");
		if (substr(trim($docs->brief), -1) == '.')
			throw new Exception("The <brief> element should not end with a dot ($methodname.xml).");
		$result['brief_description'] = self::get_inner_xml($docs->brief);
		if ($docs->{'issue-id'})
			$result['issue_id'] = (string)$docs->{'issue-id'};
		else
			$result['issue_id'] = null;
		if (!$docs->desc)
			throw new Exception("Missing <desc> element in the $methodname.xml file.");
		$result['description'] = self::get_inner_xml($docs->desc);
		$result['arguments'] = array();
		foreach ($docs->req as $arg) { $result['arguments'][] = self::arg_desc($arg); }
		foreach ($docs->opt as $arg) { $result['arguments'][] = self::arg_desc($arg); }
		foreach ($docs->{'import-params'} as $import_desc)
		{
			$attrs = $import_desc->attributes();
			$referenced_methodname = $attrs['method'];
			$referenced_method_info = OkapiServiceRunner::call('services/apiref/method',
				new OkapiInternalRequest(new OkapiInternalConsumer(), null, array('name' => $referenced_methodname)));
			$include_list = isset($attrs['params']) ? explode("|", $attrs['params']) : null;
			$exclude_list = isset($attrs['except']) ? explode("|", $attrs['except']) : array();
			foreach ($referenced_method_info['arguments'] as $arg)
			{
				if ($arg['class'] == 'common-formatting')
					continue;
				if (($include_list === null) && (count($exclude_list) == 0))
				{
					$arg['description'] = "<i>Inherited from <a href='".$referenced_method_info['ref_url'].
						"'>".$referenced_method_info['name']."</a> method.</i>";
				}
				elseif (
					(($include_list === null) || in_array($arg['name'], $include_list))
					&& (!in_array($arg['name'], $exclude_list))
				) {
					$arg['description'] = "<i>Same as in the <a href='".$referenced_method_info['ref_url'].
						"'>".$referenced_method_info['name']."</a> method.</i>";
				} else {
					continue;
				}
				$arg['class'] = 'inherited';
				$result['arguments'][] = $arg;
			}
		}
		if ($docs->{'common-format-params'})
		{
			$result['arguments'][] = array(
				'name' => 'format',
				'is_required' => false,
				'is_deprecated' => false,
				'class' => 'common-formatting',
				'description' => "<i>Standard <a href='".Settings::get('SITE_URL')."okapi/introduction.html#common-formatting'>common formatting</a> argument.</i>"
			);
			$result['arguments'][] = array(
				'name' => 'callback',
				'is_required' => false,
				'is_deprecated' => false,
				'class' => 'common-formatting',
				'description' => "<i>Standard <a href='".Settings::get('SITE_URL')."okapi/introduction.html#common-formatting'>common formatting</a> argument.</i>"
			);
		}
		foreach ($result['arguments'] as &$arg_ref)
			if ($arg_ref['is_deprecated'])
				$arg_ref['class'] .= " deprecated";
		if (!$docs->returns)
			throw new Exception("Missing <returns> element in the $methodname.xml file. ".
				"If your method does not return anything, you should document in nonetheless.");
		$result['returns'] = self::get_inner_xml($docs->returns);
		return Okapi::formatted_response($request, $result);
	}
}
