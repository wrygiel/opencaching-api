<?php

namespace okapi\views\update;

use Exception;
use okapi\Okapi;
use okapi\Cache;
use okapi\Db;
use okapi\OkapiRequest;
use okapi\OkapiRedirectResponse;
use okapi\OkapiHttpResponse;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiServiceRunner;
use okapi\OkapiInternalRequest;
use okapi\cronjobs\CronJobController;

class View
{
	public static function get_current_version()
	{
		return Okapi::get_var('db_version', 0) + 0;
	}
	
	public static function get_max_version()
	{
		$max_db_version = 0;
		foreach (get_class_methods(__CLASS__) as $name)
		{
			if (strpos($name, "ver") === 0)
			{
				$ver = substr($name, 3) + 0;
				if ($ver > $max_db_version)
					$max_db_version = $ver;
			}
		}
		return $max_db_version;
	}
	
	public static function out($str)
	{
		print $str;
		ob_flush();
		flush();
	}
	
	public static function call()
	{
		ignore_user_abort(true);
		set_time_limit(0);
		
		header("Content-Type: text/plain; charset=utf-8");
		
		$current_ver = self::get_current_version();
		$max_ver = self::get_max_version();
		self::out("Current OKAPI database version: $current_ver\n");
		if ($max_ver == $current_ver)
		{
			self::out("It is up-to-date.\n\n");
		}
		elseif ($max_ver < $current_ver)
			throw new Exception();
		else
		{
			self::out("Updating to version $max_ver... PLEASE WAIT\n\n");
			
			while ($current_ver < $max_ver)
			{
				$version_to_apply = $current_ver + 1;
				self::out("Applying mutation #$version_to_apply...");
				try {
					call_user_func(array(__CLASS__, "ver".$version_to_apply));
					self::out(" OK!\n");
					Okapi::set_var('db_version', $version_to_apply);
					$current_ver += 1;
				} catch (Exception $e) {
					self::out(" ERROR\n\n");
					throw $e;
				}
			}
			self::out("\nDatabase updated.\n\n");
		}
		
		self::out("Registering new cronjobs...\n");
		# Validate all cronjobs (some might have been added).
		Okapi::set_var("cron_nearest_event", 0);
		Okapi::execute_prerequest_cronjobs();
		
		self::out("\nUpdate complete.");
	}
	
	/**
	 * Return the list of email addresses of developers who used any of the given
	 * method names at least once. If $days is not null, then only consumers which
	 * used the method in last X $days will be returned.
	 */
	public static function get_consumers_of($service_names, $days = null)
	{
		return Db::select_column("
			select distinct c.email
			from
				okapi_consumers c,
				okapi_stats_hourly sh
			where
				sh.consumer_key = c.`key`
				and sh.service_name in ('".implode("','", array_map('mysql_real_escape_string', $service_names))."')
				".(($days != null) ? "and sh.period_start > date_add(now(), interval -".$days." day)" : "")."
		");
	}
	
	private static function ver1()
	{
		Db::execute("
			CREATE TABLE okapi_vars (
				var varchar(32) charset ascii collate ascii_bin NOT NULL,
				value text,
				PRIMARY KEY  (var)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;
		");
	}
	
	private static function ver2()
	{
		Db::execute("
			CREATE TABLE okapi_authorizations (
				consumer_key varchar(20) charset ascii collate ascii_bin NOT NULL,
				user_id int(11) NOT NULL,
				last_access_token datetime default NULL,
				PRIMARY KEY  (consumer_key,user_id)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;
		");
	}
	
	private static function ver3()
	{
		Db::execute("
			CREATE TABLE okapi_consumers (
				`key` varchar(20) charset ascii collate ascii_bin NOT NULL,
				name varchar(100) collate utf8_general_ci NOT NULL,
				secret varchar(40) charset ascii collate ascii_bin NOT NULL,
				url varchar(250) collate utf8_general_ci default NULL,
				email varchar(70) collate utf8_general_ci default NULL,
				date_created datetime NOT NULL,
				PRIMARY KEY  (`key`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;
		");
	}
	
	private static function ver4()
	{
		Db::execute("
			CREATE TABLE okapi_nonces (
				consumer_key varchar(20) charset ascii collate ascii_bin NOT NULL,
				`key` varchar(255) charset ascii collate ascii_bin NOT NULL,
				timestamp int(10) NOT NULL,
				PRIMARY KEY  (consumer_key, `key`, `timestamp`)
			) ENGINE=MEMORY DEFAULT CHARSET=utf8;
		");
	}
	
	private static function ver5()
	{
		Db::execute("
			CREATE TABLE okapi_tokens (
				`key` varchar(20) charset ascii collate ascii_bin NOT NULL,
				secret varchar(40) charset ascii collate ascii_bin NOT NULL,
				token_type enum('request','access') NOT NULL,
				timestamp int(10) NOT NULL,
				user_id int(10) default NULL,
				consumer_key varchar(20) charset ascii collate ascii_bin NOT NULL,
				verifier varchar(10) charset ascii collate ascii_bin default NULL,
				callback varchar(2083) character set utf8 collate utf8_general_ci default NULL,
				PRIMARY KEY  (`key`),
				KEY by_consumer (consumer_key)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;
		");
	}
	
	private static function ver6()
	{
		# Removed this update. It seemed dangerous to run such updates on unknown OC installations.
	}
	
	private static function ver7()
	{
		# In fact, this should be "alter cache_logs add column okapi_consumer_key...", but
		# I don't want for OKAPI to mess with the rest of DB. Keeping it separete for now.
		# One day, this table could come in handy. See:
		# http://code.google.com/p/opencaching-api/issues/detail?id=64
		Db::execute("
			CREATE TABLE okapi_cache_logs (
				log_id int(11) NOT NULL,
				consumer_key varchar(20) charset ascii collate ascii_bin NOT NULL,
				PRIMARY KEY  (log_id),
				KEY by_consumer (consumer_key)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;
		");
	}
	
	private static function ver8()
	{
		Db::execute("
			CREATE TABLE okapi_cache (
				`key` varchar(32) NOT NULL,
				value blob,
				expires datetime,
				PRIMARY KEY  (`key`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;
		");
	}
	
	private static function ver9() { Db::execute("alter table okapi_consumers modify column `key` varchar(20) not null"); }
	private static function ver10() { Db::execute("alter table okapi_consumers modify column secret varchar(40) not null"); }
	private static function ver11() { Db::execute("alter table okapi_tokens modify column `key` varchar(20) not null"); }
	private static function ver12() { Db::execute("alter table okapi_tokens modify column secret varchar(40) not null"); }
	private static function ver13() { Db::execute("alter table okapi_tokens modify column consumer_key varchar(20) not null"); }
	private static function ver14() { Db::execute("alter table okapi_tokens modify column verifier varchar(10) default null"); }
	private static function ver15() { Db::execute("alter table okapi_authorizations modify column consumer_key varchar(20) not null"); }
	private static function ver16() { Db::execute("alter table okapi_nonces modify column consumer_key varchar(20) not null"); }
	private static function ver17() { Db::execute("alter table okapi_nonces modify column `key` varchar(255) not null"); }
	private static function ver18() { Db::execute("alter table okapi_cache_logs modify column consumer_key varchar(20) not null"); }
	private static function ver19() { Db::execute("alter table okapi_vars modify column `var` varchar(32) not null"); }
	
	private static function ver20() { Db::execute("alter table okapi_consumers modify column `key` varchar(20) collate utf8_bin not null"); }
	private static function ver21() { Db::execute("alter table okapi_consumers modify column secret varchar(40) collate utf8_bin not null"); }
	private static function ver22() { Db::execute("alter table okapi_tokens modify column `key` varchar(20) collate utf8_bin not null"); }
	private static function ver23() { Db::execute("alter table okapi_tokens modify column secret varchar(40) collate utf8_bin not null"); }
	private static function ver24() { Db::execute("alter table okapi_tokens modify column consumer_key varchar(20) collate utf8_bin not null"); }
	private static function ver25() { Db::execute("alter table okapi_tokens modify column verifier varchar(10) collate utf8_bin default null"); }
	private static function ver26() { Db::execute("alter table okapi_authorizations modify column consumer_key varchar(20) collate utf8_bin not null"); }
	private static function ver27() { Db::execute("alter table okapi_nonces modify column consumer_key varchar(20) collate utf8_bin not null"); }
	private static function ver28() { Db::execute("alter table okapi_nonces modify column `key` varchar(255) collate utf8_bin not null"); }
	private static function ver29() { Db::execute("alter table okapi_cache_logs modify column consumer_key varchar(20) collate utf8_bin not null"); }
	private static function ver30() { Db::execute("alter table okapi_vars modify column `var` varchar(32) collate utf8_bin not null"); }
	
	private static function ver31()
	{
		Db::execute("
			CREATE TABLE `okapi_stats_temp` (
				`datetime` datetime NOT NULL,
				`consumer_key` varchar(32) NOT NULL DEFAULT 'internal',
				`user_id` int(10) NOT NULL DEFAULT '-1',
				`service_name` varchar(80) NOT NULL,
				`calltype` enum('internal','http') NOT NULL,
				`runtime` float NOT NULL DEFAULT '0'
			) ENGINE=MEMORY DEFAULT CHARSET=utf8
		");
	}
	
	private static function ver32()
	{
		Db::execute("
			CREATE TABLE `okapi_stats_hourly` (
				`consumer_key` varchar(32) NOT NULL,
				`user_id` int(10) NOT NULL,
				`period_start` datetime NOT NULL,
				`service_name` varchar(80) NOT NULL,
				`total_calls` int(10) NOT NULL,
				`http_calls` int(10) NOT NULL,
				`total_runtime` float NOT NULL DEFAULT '0',
				`http_runtime` float NOT NULL DEFAULT '0',
				PRIMARY KEY (`consumer_key`,`user_id`,`period_start`,`service_name`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8
		");
	}
	
	private static function ver33()
	{
		$spec = Db::select_value("show create table cache_logs");
		$key_exists = (strpos($spec, "(`uuid`") !== false);
		if ($key_exists)
			return;
		Db::execute("alter table cache_logs add key `uuid` (`uuid`)");
	}
	
	private static function ver34()
	{
		Db::execute("
			CREATE TABLE `okapi_clog` (
				id int(10) not null auto_increment,
				data blob default null,
				PRIMARY KEY (id)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8
		");
	}
	
	private static function ver35()
	{
		# Inform the admin about the new cronjobs.
		Okapi::mail_admins(
			"Additional setup needed: cronjobs support",
			"Hello there, you've just updated OKAPI on your server. Thanks!\n\n".
			"We need you to do one more thing. This version of OKAPI requires\n".
			"additional crontab entry. Please add the following line to your crontab:\n\n".
			"*/5 * * * * wget -O - -q -t 1 ".$GLOBALS['absolute_server_URI']."okapi/cron5\n\n".
			"This is required for OKAPI to function properly from now on.\n\n".
			"-- \n".
			"Thanks, OKAPI developers."
		);
	}
	
	private static function ver36() { Db::execute("alter table okapi_cache modify column `key` varchar(64) not null"); }
	private static function ver37() { Db::execute("delete from okapi_vars where var='last_clog_update'"); }
	private static function ver38() { Db::execute("alter table okapi_clog modify column data mediumblob"); }
	private static function ver39() { Db::execute("delete from okapi_clog"); }
	private static function ver40() { Db::execute("alter table okapi_cache modify column value mediumblob"); }
	
	private static function ver41()
	{
		# Force changelog reset (will be produced one day back)
		Db::execute("delete from okapi_vars where var='last_clog_update'");

		# Force all cronjobs rerun
		Okapi::set_var("cron_nearest_event", 0);
		Cache::delete('cron_schedule');
	}
	
	private static function ver42() { Db::execute("delete from okapi_cache where length(value) = 65535"); }
	
	private static function ver43()
	{
		$emails = self::get_consumers_of(array('services/replicate/changelog', 'services/replicate/fulldump'), 14);
		ob_start();
		print "Hi!\n\n";
		print "We send this email to all developers who used 'replicate' module\n";
		print "in last 14 days. Thank you for testing our BETA-status module.\n\n";
		print "As you probably know, BETA status implies that we may decide to\n";
		print "modify something in a backward-incompatible way. One of such\n";
		print "modifications just happened and it may concern you.\n\n";
		print "We removed 'attrnames' from the list of synchronized fields of\n";
		print "'geocache'-type objects. Watch our blog for updates!\n\n";
		print "-- \n";
		print "OKAPI Team";
		Okapi::mail_from_okapi($emails, "A change in the 'replicate' module.", ob_get_clean());
	}
	
	private static function ver44() { Db::execute("alter table caches add column okapi_syncbase timestamp not null after last_modified;"); }
	private static function ver45() { Db::execute("update caches set okapi_syncbase=last_modified;"); }
	private static function ver46() { Db::execute("update caches set okapi_syncbase=now() where last_found < '1980-01-01'"); }
	
	private static function ver47()
	{
		Db::execute("
			update caches
			set okapi_syncbase=now()
			where cache_id in (
				select cache_id
				from cache_logs
				where date_created > '2012-03-11' -- the day when 'replicate' module was introduced
			);
		");
	}
	
	private static function ver48()
	{
		ob_start();
		print "Hi!\n\n";
		print "OKAPI just added additional field (along with an index) 'okapi_syncbase'\n";
		print "on your 'caches' table. It is required by OKAPI's 'replicate' module to\n";
		print "function properly.\n\n";
		self::print_common_db_alteration_info();
		print "-- \n";
		print "OKAPI Team";
		Okapi::mail_admins("Database modification notice: caches.okapi_syncbase", ob_get_clean());
	}
	
	private static function print_common_db_alteration_info()
	{
		print "-- About OKAPI's database modifications --\n\n";
		print "OKAPI takes care of its own tables (the ones with the \"okapi_\"\n";
		print "prefix), but it won't usually alter other tables in your\n";
		print "database. Still, sometimes we may change something\n";
		print "slightly (either to make OKAPI work properly OR as a part of\n";
		print "bigger \"international OpenCaching unification\" ideas).\n\n";
		print "We will let you know every time OKAPI alters database structure\n";
		print "(outside of the \"okapi_\" table-scope).\n\n";
	}
	
	private static function ver49() { Db::execute("alter table caches add key okapi_syncbase (okapi_syncbase);"); }
}
