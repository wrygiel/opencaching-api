<?php

namespace okapi\core;

use Exception;
use okapi\core\Consumer\OkapiConsumer;
use okapi\core\CronJob\CronJobController;
use okapi\core\Exception\BadRequest;
use okapi\core\Exception\DbException;
use okapi\core\Exception\InvalidParam;
use okapi\core\Exception\JobsAlreadyInProgress;
use okapi\core\Exception\OkapiExceptionHandler;
use okapi\core\OAuth\OkapiOAuthServer;
use okapi\core\Request\OkapiInternalRequest;
use okapi\core\Request\OkapiRequest;
use okapi\core\Response\OkapiHttpResponse;
use okapi\Settings;

/** Container for various OKAPI functions. */
class Okapi
{
    public static $data_store;
    public static $server;

    /* These two get replaced in automatically deployed packages. */
    private static $version_number = null;
    private static $git_revision = null;

    private static $okapi_vars = null;

    public static function getVersionNumber()
    {
        if (!self::$version_number)
        {
            $versionFile = Settings::get('VERSION_FILE');
            if ($versionFile) {
                $meta = include $versionFile;
                self::$version_number = $meta['version_number'];
            }
        }
        return self::$version_number;
    }

    public static function getGitRevision()
    {
        if (!self::$git_revision)
        {
            $versionFile = Settings::get('VERSION_FILE');
            if ($versionFile) {
                $meta = include $versionFile;
                self::$git_revision = $meta['git_revision'];
            }
        }
        return self::$git_revision;
    }

    /** Return a new, random UUID. */
    public static function create_uuid()
    {
        /* If we're on Linux, then we'll use a system function for that. */

        if (file_exists("/proc/sys/kernel/random/uuid")) {
            return trim(file_get_contents("/proc/sys/kernel/random/uuid"));
        }

        /* On other systems (as well as on some other Linux distributions)
         * fall back to the original implementation (which is NOT safe - we had
         * one duplicate during 3 years of its running). */

        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Convert float to string independent from LC_NUMERIC setting, which is not
     * thread-safe. See issue #536.
     *
     * 8 decimal places should exceed the precision of all Okapi float output:
     * Coordinates, Terrain/Difficulty, Ratings, OX sizes etc.
     */
    public static function float2string($number)
    {
        if (round($number, 8) == round($number, 0))
            return "" . round($number, 0);
        else
            return rtrim(number_format($number, 9, '.', ''), '0');
    }

    /** Get a variable stored in okapi_vars. If variable not found, return $default. */
    public static function get_var($varname, $default = null)
    {
        if (self::$okapi_vars === null)
        {
            $rs = Db::query("
                select var, value
                from okapi_vars
            ");
            self::$okapi_vars = array();
            while ($row = Db::fetch_assoc($rs))
                self::$okapi_vars[$row['var']] = $row['value'];
        }
        if (isset(self::$okapi_vars[$varname]))
            return self::$okapi_vars[$varname];
        return $default;
    }

    /**
     * Save a variable to okapi_vars. WARNING: The entire content of okapi_vars table
     * is loaded on EVERY execution. Do not store data in this table, unless it's
     * frequently needed.
     */
    public static function set_var($varname, $value)
    {
        Okapi::get_var($varname);
        Db::execute("
            replace into okapi_vars (var, value)
            values (
                '".Db::escape_string($varname)."',
                '".Db::escape_string($value)."');
        ");
        self::$okapi_vars[$varname] = $value;
    }

    /**
     * Remove database passwords and other sensitive data from the given
     * message (which is usually a trace, var_dump or print_r output).
     */
    public static function removeSensitiveData($message)
    {
        # This method is initially defined in the OkapiExceptionHandler class,
        # so that it is accessible even before the Okapi class is initialized.

        return OkapiExceptionHandler::removeSensitiveData($message);
    }

    /** Send an email message to local OKAPI administrators. */
    public static function mail_admins($subject, $message)
    {
        # Make sure we're not sending HUGE emails.

        if (strlen($message) > 100000) {
            $message = substr($message, 0, 100000)."\n\n...(message clipped at 100k chars)\n";
        }

        # Make sure we're not spamming.

        $cache_key = 'mail_admins_counter/'.(floor(time() / 1800) * 1800).'/'.md5($subject);
        try {
            $counter = Cache::get($cache_key);
        } catch (\Exception $e) {
            # Exception can occur during OKAPI update (#156, #434), or when
            # the cache table is broken (#340). I am not sure which option is
            # better: 1. notify the admins about the error and risk spamming
            # them, 2. don't notify and don't risk spamming them. Currently,
            # I choose option 2.

            return;
        }
        if ($counter === null)
            $counter = 0;
        $counter++;
        try {
            Cache::set($cache_key, $counter, 1800);
        } catch (DbException $e) {
            # If `get` succeeded and `set` did not, then probably we're having
            # issue #156 scenario. We can ignore it here.
        }
        if ($counter <= 2)
        {
            # We're not spamming yet.

            self::mail_from_okapi(\get_admin_emails(), $subject, $message);
        }
        else
        {
            # We are spamming. Prevent sending more emails.

            $content_cache_key_prefix = 'mail_admins_spam/'.(floor(time() / 1800) * 1800).'/';
            $timeout = 86400;
            if ($counter == 3)
            {
                self::mail_from_okapi(\get_admin_emails(), "Anti-spam mode activated for '$subject'",
                    "OKAPI has activated an \"anti-spam\" mode for the following subject:\n\n".
                    "\"$subject\"\n\n".
                    "Anti-spam mode is activiated when more than 2 messages with\n".
                    "the same subject are sent within half an hour.\n\n".
                    "Additional debug information:\n".
                    "- counter cache key: $cache_key\n".
                    "- content prefix: $content_cache_key_prefix\n".
                    "- content timeout: $timeout\n"
                );
            }
            $content_cache_key = $content_cache_key_prefix.$counter;
            Cache::set($content_cache_key, $message, $timeout);
        }
    }

    /** Send an email message from OKAPI to the given recipients. */
    public static function mail_from_okapi($email_addresses, $subject, $message)
    {
        if (class_exists("okapi\\Settings") && (Settings::get('DEBUG_PREVENT_EMAILS')))
        {
            # Sending emails was blocked on admin's demand.
            # This is possible only on development environment.
            return;
        }
        if (!is_array($email_addresses))
            $email_addresses = array($email_addresses);
        $sender_email = class_exists("okapi\\Settings") ? Settings::get('FROM_FIELD') : 'root@localhost';
        mail(implode(", ", $email_addresses), $subject, $message,
            "Content-Type: text/plain; charset=utf-8\n".
            "From: OKAPI <$sender_email>\n".
            "Reply-To: $sender_email\n"
        );
    }

    /** Get directory to store dynamic (cache or temporary) files. No trailing slash included. */
    public static function get_var_dir()
    {
        $dir = Settings::get('VAR_DIR');
        if ($dir != null)
            return rtrim($dir, "/");
        throw new Exception("You need to set a valid VAR_DIR.");
    }

    /** Returns something like "Opencaching.PL" or "Opencaching.DE". */
    public static function get_normalized_site_name($site_url = null)
    {
        if ($site_url == null)
            $site_url = Settings::get('SITE_URL');
        $matches = null;
        if (preg_match("#^https?://(www.)?opencaching.([a-z.]+)/$#", $site_url, $matches)) {
            return "Opencaching.".strtoupper($matches[2]);
        }
        if (preg_match("#^https?://(www.)?opencache.([a-z.]+)/$#", $site_url, $matches)) {
            return "Opencache.".strtoupper($matches[2]);
        }

        return "DEVELSITE";
    }

    /**
     * Return a "code" of this OC node.
     *
     * These values are used internally only, they SHOULD NOT be exposed to
     * external developers!
     */
    public static function get_oc_installation_code()
    {
        if (Settings::get('OC_BRANCH') == 'oc.de') {
            return "OCDE";  // OC
        }
        $mapping = array(
            2 => "OCPL",  // OP
            6 => "OCUK",  // OK
            10 => "OCUS",  // OU
            14 => "OCNL",  // OB
            16 => "OCRO",  // OR
            // should be expanded when new OCPL-based sites are added
        );
        $oc_node_id = Settings::get("OC_NODE_ID");
        if (isset($mapping[$oc_node_id])) {
            return $mapping[$oc_node_id];
        }

        return "OTHER";
    }

    /**
     * Return the recommended okapi_base_url.
     *
     * This is the URL which we want all *new* client applications to use.
     * OKAPI will suggest URLs with this prefix in various context, e.g. in all
     * the dynamically generated docs.
     *
     * Also see `get_allowed_base_urls` method.
     */
    public static function get_recommended_base_url()
    {
        return Settings::get('SITE_URL')."okapi/";
    }

    /**
     * Return a list of okapi_base_urls allowed to be used when calling OKAPI
     * methods in this installation.
     *
     * Since issue #416, the "recommended" okapi_base_url is *not* the only one
     * allowed (actually, there were more allowed before issue #416, but they
     * weren't allowed "officially").
     */
    public static function get_allowed_base_urls()
    {
        /* Currently, there are no config settings which would let us allow
         * to determine the proper values for this list. So, we need to have it
         * hardcoded. (Perhaps we should move this to etc/installations.xml?
         * But this wouldn't be efficient...) */

        switch (self::get_oc_installation_code()) {
            case 'OCPL':
                $urls = array(
                    "http://opencaching.pl/okapi/",
                    "http://www.opencaching.pl/okapi/",
                    "https://opencaching.pl/okapi/",
                );
                break;
            case 'OCDE':
                if (in_array(Settings::get('OC_NODE_ID'), array(4,5))) {
                    /* In OCDE, node_ids 4 and 5 are used to indicate a development
                     * installation. Other sites rely on the fact, that
                     * self::get_recommended_base_url() is appended to $urls below.
                     * For OCDE this is not enough, because they want to test both
                     * HTTP and HTTPS in their development installations. */
                    $urls = array(
                        preg_replace("/^https:/", "http:", Settings::get('SITE_URL')) . 'okapi/',
                        preg_replace("/^http:/", "https:", Settings::get('SITE_URL')) . 'okapi/',
                    );
                } else {
                    $urls = array(
                        "http://www.opencaching.de/okapi/",
                        "https://www.opencaching.de/okapi/",
                    );
                }
                break;
            case 'OCNL':
                $urls = array(
                    "http://www.opencaching.nl/okapi/",
                    "https://www.opencaching.nl/okapi/",
                );
                break;
            case 'OCRO':
                $urls = array(
                    "http://www.opencaching.ro/okapi/",
                    "https://www.opencaching.ro/okapi/",
                );
                break;
            case 'OCUK':
                $urls = array(
                    "http://opencache.uk/okapi/",
                    "https://opencache.uk/okapi/",
                );
                break;
            case 'OCUS':
                $urls = array(
                    "http://www.opencaching.us/okapi/",
                    "https://www.opencaching.us/okapi/",
                );
                break;
            default:
                /* Unknown site. No extra allowed URLs. */
                $urls = array();
        }

        if (!in_array(self::get_recommended_base_url(), $urls)) {
            $urls[] = self::get_recommended_base_url();
        }

        return $urls;
    }

    /**
     * Pick text from $langdict based on language preference $langpref.
     *
     * Example:
     * pick_best_language(
     *   array('pl' => 'X', 'de' => 'Y', 'en' => 'Z'),
     *   array('sp', 'de', 'en')
     * ) == 'Y'.
     *
     * @param array $langdict - assoc array of lang-code => text.
     * @param array $langprefs - list of lang codes, in order of preference.
     */
    public static function pick_best_language($langdict, $langprefs)
    {
        /* Try langprefs first. */
        foreach ($langprefs as $pref) {
            if (isset($langdict[$pref])) {
                return $langdict[$pref];
            }
        }

        /* Try English next. */
        if (isset($langdict['en'])) {
            return $langdict['en'];
        }

        /* Then, try SITELANG. Should be filled in most cases. */
        if (isset($langdict[Settings::get('SITELANG')])) {
            return $langdict[Settings::get('SITELANG')];
        }

        /* Finally, just pick any. */
        foreach ($langdict as &$text_ref)
            return $text_ref;

        /* Langdict is empty. Simply return an empty string. */
        return "";
    }

    /**
     * Split the array into groups of max. $size items.
     */
    public static function make_groups($array, $size)
    {
        $i = 0;
        $groups = array();
        while ($i < count($array))
        {
            $groups[] = array_slice($array, $i, $size);
            $i += $size;
        }
        return $groups;
    }

    /**
     * Check if any pre-request cronjobs are scheduled to execute and execute
     * them if needed. Reschedule for new executions.
     */
    public static function execute_prerequest_cronjobs()
    {
        $nearest_event = Okapi::get_var("cron_nearest_event");
        if ($nearest_event + 0 <= time())
        {
            try {
                $nearest_event = CronJobController::run_jobs('pre-request');
                Okapi::set_var("cron_nearest_event", $nearest_event);
            } catch (JobsAlreadyInProgress $e) {
                // Ignore.
            }
        }
    }

    /**
     * Check if any cron-5 cronjobs are scheduled to execute and execute
     * them if needed. Reschedule for new executions.
     *
     * If other thread is currently handling the jobs, then do nothing.
     */
    public static function execute_cron5_cronjobs()
    {
        $nearest_event = Okapi::get_var("cron_nearest_event");
        if ($nearest_event + 0 <= time())
        {
            set_time_limit(0);
            ignore_user_abort(true);
            try {
                $nearest_event = CronJobController::run_jobs('cron-5');
                Okapi::set_var("cron_nearest_event", $nearest_event);
            } catch (JobsAlreadyInProgress $e) {
                // Ignore.
            }
        }
    }

    private static function gettext_set_lang($langprefs)
    {
        static $gettext_last_used_langprefs = null;
        static $gettext_last_set_locale = null;

        # We remember the last $langprefs argument which we've been called with.
        # This way, we don't need to call the actual locale-switching code most
        # of the times.

        if ($gettext_last_used_langprefs != $langprefs)
        {
            $gettext_last_set_locale = call_user_func(Settings::get("GETTEXT_INIT"), $langprefs);
            $gettext_last_used_langprefs = $langprefs;
            textdomain(Settings::get("GETTEXT_DOMAIN"));
        }
        return $gettext_last_set_locale;
    }

    private static $gettext_original_domain = null;
    private static $gettext_langprefs_stack = array();

    /**
     * Attempt to switch the language based on the preference array given.
     * Previous language settings will be remembered (in a stack). You SHOULD
     * restore them later by calling gettext_domain_restore.
     */
    public static function gettext_domain_init($langprefs = null)
    {
        # Put the langprefs on the stack.

        if ($langprefs == null)
            $langprefs = array(Settings::get('SITELANG'));
        self::$gettext_langprefs_stack[] = $langprefs;

        if (count(self::$gettext_langprefs_stack) == 1)
        {
            # This is the first time gettext_domain_init is called. In order to
            # properly reinitialize the original settings after gettext_domain_restore
            # is called for the last time, we need to save current textdomain (which
            # should be different than the one which we use - Settings::get("GETTEXT_DOMAIN")).

            self::$gettext_original_domain = textdomain(null);
        }

        # Attempt to change the language. Acquire the actual locale code used
        # (might differ from expected when language was not found).

        return self::gettext_set_lang($langprefs);
    }
    public static function gettext_domain_restore()
    {
        # Dismiss the last element on the langpref stack. This is the language
        # which we've been actualy using until now. We want it replaced with
        # the language below it.

        array_pop(self::$gettext_langprefs_stack);

        $size = count(self::$gettext_langprefs_stack);
        if ($size > 0)
        {
            $langprefs = self::$gettext_langprefs_stack[$size - 1];
            self::gettext_set_lang($langprefs);
        }
        else
        {
            # The stack is empty. This means we're going out of OKAPI code and
            # we want the original textdomain reestablished.

            textdomain(self::$gettext_original_domain);
            self::$gettext_original_domain = null;
        }
    }

    /**
     * Internal. This is called always when OKAPI core is included.
     */
    public static function init_internals($allow_cronjobs = true)
    {
        static $init_made = false;
        if ($init_made)
            return;
        self::increase_memory_limit('512M');
        # The memory limit is - among other - crucial for the maximum size
        # of processable images; see services/logs/images/add.php: max_pixels()
        Db::connect();
        if (Settings::get('TIMEZONE') !== null)
            date_default_timezone_set(Settings::get('TIMEZONE'));
        if (!self::$data_store)
            self::$data_store = new OkapiDataStore();
        if (!self::$server)
            self::$server = new OkapiOAuthServer(self::$data_store);
        if ($allow_cronjobs)
            self::execute_prerequest_cronjobs();
        $init_made = true;
    }

    /**
     * Increase memory limit (only if $value is larger than the current memory
     * limit).
     */
    public static function increase_memory_limit($value)
    {
        $current = self::from_human_to_bytes(ini_get('memory_limit'));
        $new = self::from_human_to_bytes($value);
        if ($current < $new) {
            ini_set('memory_limit', $value);
        }
    }

    /**
     * Generate a string of random characters, suitable for keys as passwords.
     * Troublesome characters like '0', 'O', '1', 'l' will not be used.
     * If $user_friendly=true, then it will consist from numbers only.
     */
    public static function generate_key($length, $user_friendly = false)
    {
        if ($user_friendly)
            $chars = "0123456789";
        else
            $chars = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789";
        $max = strlen($chars);
        $key = "";
        for ($i=0; $i<$length; $i++)
        {
            $key .= $chars[rand(0, $max-1)];
        }
        return $key;
    }

    /**
     * Register new OKAPI Consumer, send him an email with his key-pair, etc.
     * This method does not verify parameter values, check if they are in
     * a correct format prior the execution.
     */
    public static function register_new_consumer($appname, $appurl, $email)
    {
        $consumer = new OkapiConsumer(Okapi::generate_key(20), Okapi::generate_key(40),
            $appname, $appurl, $email);
        $sample_cache = OkapiServiceRunner::call("services/caches/search/all",
            new OkapiInternalRequest($consumer, null, array('limit', 1)));
        if (count($sample_cache['results']) > 0)
            $sample_cache_code = $sample_cache['results'][0];
        else
            $sample_cache_code = "CACHECODE";

        # Message for the Consumer.
        ob_start();
        print "This is the key-pair we have created for your application:\n\n";
        print "Consumer Key: $consumer->key\n";
        print "Consumer Secret: $consumer->secret\n\n";
        print "Note: Consumer Secret is needed only when you intend to use OAuth.\n";
        print "You don't need Consumer Secret for Level 1 Authentication.\n\n";
        print "Now you can easily access Level 1 OKAPI methods. E.g.:\n";
        print Settings::get('SITE_URL')."okapi/services/caches/geocache?cache_code=$sample_cache_code&consumer_key=$consumer->key\n\n";
        print "If you plan on using OKAPI for a longer time, then you might also want\n";
        print "to subscribe to the OKAPI News blog (it is not much updated, but it might\n";
        print "still be worth the trouble):\n";
        print "https://opencaching-api.blogspot.com/\n\n";
        print "Have fun!\n\n";
        print "-- \n";
        print "OKAPI Team\n";
        Okapi::mail_from_okapi($email, "Your OKAPI Consumer Key", ob_get_clean());

        # Message for the Admins.

        ob_start();
        print "Name: $consumer->name\n";
        print "Developer: $consumer->email\n";
        print ($consumer->url ? "URL: $consumer->url\n" : "");
        print "Consumer Key: $consumer->key\n";
        Okapi::mail_admins("New OKAPI app registered!", ob_get_clean());

        Db::execute("
            insert into okapi_consumers (`key`, name, secret, url, email, date_created)
            values (
                '".Db::escape_string($consumer->key)."',
                '".Db::escape_string($consumer->name)."',
                '".Db::escape_string($consumer->secret)."',
                '".Db::escape_string($consumer->url)."',
                '".Db::escape_string($consumer->email)."',
                now()
            );
        ");
    }

    /** Return the distance between two geopoints, in meters. */
    public static function get_distance($lat1, $lon1, $lat2, $lon2)
    {
        $x1 = (90-$lat1) * 3.14159 / 180;
        $x2 = (90-$lat2) * 3.14159 / 180;
        #
        # min(1, ...) was added below to prevent getting values greater than 1 due
        # to floating point precision limits. See issue #351 for details.
        #
        $d = acos(min(1, cos($x1) * cos($x2) + sin($x1) * sin($x2) * cos(($lon1-$lon2) * 3.14159 / 180))) * 6371000;
        if ($d < 0) $d = 0;
        return $d;
    }

    /**
     * Return an SQL formula for calculating distance between two geopoints.
     * Parameters should be either numberals or strings (SQL field references).
     */
    public static function get_distance_sql($lat1, $lon1, $lat2, $lon2)
    {
        if (is_numeric($lat1)) $lat1 = Db::float_sql($lat1);
        if (is_numeric($lon1)) $lon1 = Db::float_sql($lon1);
        if (is_numeric($lat2)) $lat2 = Db::float_sql($lat2);
        if (is_numeric($lon2)) $lon2 = Db::float_sql($lon2);

        $x1 = "(90-$lat1) * 3.14159 / 180";
        $x2 = "(90-$lat2) * 3.14159 / 180";
        #
        # least(1, ...) was added below to prevent getting values greater than 1 due
        # to floating point precision limits. See issue #351 for details.
        #
        $d = "acos(least(1, cos($x1) * cos($x2) + sin($x1) * sin($x2) * cos(($lon1-$lon2) * 3.14159 / 180))) * 6371000";
        return $d;
    }

    /** Return bearing (float 0..360) from geopoint 1 to 2. */
    public static function get_bearing($lat1, $lon1, $lat2, $lon2)
    {
        if ($lat1 == $lat2 && $lon1 == $lon2)
            return null;
        if ($lat1 == $lat2) $lat1 += 0.0000166;
        if ($lon1 == $lon2) $lon1 += 0.0000166;

        $rad_lat1 = $lat1 / 180.0 * 3.14159;
        $rad_lon1 = $lon1 / 180.0 * 3.14159;
        $rad_lat2 = $lat2 / 180.0 * 3.14159;
        $rad_lon2 = $lon2 / 180.0 * 3.14159;

        $delta_lon = $rad_lon2 - $rad_lon1;
        $bearing = atan2(sin($delta_lon) * cos($rad_lat2),
            cos($rad_lat1) * sin($rad_lat2) - sin($rad_lat1) * cos($rad_lat2) * cos($delta_lon));
        $bearing = 180.0 * $bearing / 3.14159;
        if ( $bearing < 0.0 ) $bearing = $bearing + 360.0;

        return $bearing;
    }

    /** Transform bearing (float 0..360) to simple 2-letter string (N, NE, E, SE, etc.) */
    public static function bearing_as_two_letters($b)
    {
        static $names = array('N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW');
        if ($b === null) return 'n/a';
        return $names[round(($b / 360.0) * 8.0) % 8];
    }

    /** Transform bearing (float 0..360) to simple 3-letter string (N, NNE, NE, ESE, etc.) */
    public static function bearing_as_three_letters($b)
    {
        static $names = array('N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE',
            'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW');
        if ($b === null) return 'n/a';
        return $names[round(($b / 360.0) * 16.0) % 16];
    }

    /** Escape string for use with XML. See issue 169. */
    public static function xmlescape($string)
    {
        static $pattern = '/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u';
        $string = preg_replace($pattern, '', $string);
        return strtr($string, array("<" => "&lt;", ">" => "&gt;", "\"" => "&quot;", "'" => "&apos;", "&" => "&amp;"));
    }

    /**
     * Return object as a standard OKAPI response. The $object will be formatted
     * using one of the default formatters (JSON, JSONP, XML, etc.). Formatter is
     * auto-detected by peeking on the $request's 'format' parameter. In some
     * specific cases, this method can also return the $object itself, instead
     * of OkapiResponse - this allows nesting methods within other methods.
     */
    public static function formatted_response(OkapiRequest $request, &$object)
    {
        if ($request instanceof OkapiInternalRequest && (!$request->i_want_OkapiResponse))
        {
            # If you call a method internally, then you probably expect to get
            # the actual object instead of it's formatted representation.
            return $object;
        }
        $format = $request->get_parameter('format');
        if ($format == null) $format = 'json';
        if (!in_array($format, array('json', 'jsonp', 'xmlmap', 'xmlmap2')))
            throw new InvalidParam('format', "'$format'");
        $callback = $request->get_parameter('callback');
        if ($callback && $format != 'jsonp')
            throw new BadRequest("The 'callback' parameter is reserved to be used with the JSONP output format.");
        if ($format == 'json')
        {
            $response = new OkapiHttpResponse();
            $response->content_type = "application/json; charset=utf-8";
            $response->body = json_encode($object);
            return $response;
        }
        elseif ($format == 'jsonp')
        {
            if (!$callback)
                throw new BadRequest("'callback' parameter is required for JSONP calls");
            if (!preg_match("/^[a-zA-Z_][a-zA-Z0-9_]*$/", $callback))
                throw new InvalidParam('callback', "'$callback' doesn't seem to be a valid JavaScript function name (should match /^[a-zA-Z_][a-zA-Z0-9_]*\$/).");
            $response = new OkapiHttpResponse();
            $response->content_type = "application/javascript; charset=utf-8";
            $response->body = $callback."(".json_encode($object).");";
            return $response;
        }
        elseif ($format == 'xmlmap')
        {
            # Deprecated (see issue 128). Keeping this for backward-compatibility.
            $response = new OkapiHttpResponse();
            $response->content_type = "text/xml; charset=utf-8";
            $response->body = self::xmlmap_dumps($object);
            return $response;
        }
        elseif ($format == 'xmlmap2')
        {
            $response = new OkapiHttpResponse();
            $response->content_type = "text/xml; charset=utf-8";
            $response->body = self::xmlmap2_dumps($object);
            return $response;
        }
        else
        {
            # Should not happen (as we do a proper check above).
            throw new Exception();
        }
    }

    private static function _xmlmap_add(&$chunks, &$obj)
    {
        if (is_string($obj))
        {
            $chunks[] = "<string>";
            $chunks[] = self::xmlescape($obj);
            $chunks[] = "</string>";
        }
        elseif (is_int($obj))
        {
            $chunks[] = "<int>$obj</int>";
        }
        elseif (is_float($obj))
        {
            $chunks[] = "<float>$obj</float>";
        }
        elseif (is_bool($obj))
        {
            $chunks[] = $obj ? "<bool>true</bool>" : "<bool>false</bool>";
        }
        elseif (is_null($obj))
        {
            $chunks[] = "<null/>";
        }
        elseif (is_array($obj))
        {
            # Have to check if this is associative or not! Shit. I hate PHP.
            if (array_keys($obj) === range(0, count($obj) - 1))
            {
                # Not assoc.
                $chunks[] = "<list>";
                foreach ($obj as &$item_ref)
                {
                    $chunks[] = "<item>";
                    self::_xmlmap_add($chunks, $item_ref);
                    $chunks[] = "</item>";
                }
                $chunks[] = "</list>";
            }
            else
            {
                # Assoc.
                $chunks[] = "<dict>";
                foreach ($obj as $key => &$item_ref)
                {
                    $chunks[] = "<item key=\"".self::xmlescape($key)."\">";
                    self::_xmlmap_add($chunks, $item_ref);
                    $chunks[] = "</item>";
                }
                $chunks[] = "</dict>";
            }
        }
        else
        {
            # That's a bug.
            throw new Exception("Cannot encode as xmlmap: " . print_r($obj, true));
        }
    }

    private static function _xmlmap2_add(&$chunks, &$obj, $key)
    {
        $attrs = ($key !== null) ? " key=\"".self::xmlescape($key)."\"" : "";
        if (is_string($obj))
        {
            $chunks[] = "<string$attrs>";
            $chunks[] = self::xmlescape($obj);
            $chunks[] = "</string>";
        }
        elseif (is_int($obj))
        {
            $chunks[] = "<number$attrs>$obj</number>";
        }
        elseif (is_float($obj))
        {
            $chunks[] = "<number$attrs>$obj</number>";
        }
        elseif (is_bool($obj))
        {
            $chunks[] = $obj ? "<boolean$attrs>true</boolean>" : "<boolean$attrs>false</boolean>";
        }
        elseif (is_null($obj))
        {
            $chunks[] = "<null$attrs/>";
        }
        elseif (is_array($obj) || ($obj instanceof \ArrayObject))
        {
            # Have to check if this is associative or not! Shit. I hate PHP.
            if (is_array($obj) && (array_keys($obj) === range(0, count($obj) - 1)))
            {
                # Not assoc.
                $chunks[] = "<array$attrs>";
                foreach ($obj as &$item_ref)
                {
                    self::_xmlmap2_add($chunks, $item_ref, null);
                }
                $chunks[] = "</array>";
            }
            else
            {
                # Assoc.
                $chunks[] = "<object$attrs>";
                foreach ($obj as $key => &$item_ref)
                {
                    self::_xmlmap2_add($chunks, $item_ref, $key);
                }
                $chunks[] = "</object>";
            }
        }
        else
        {
            # That's a bug.
            throw new Exception("Cannot encode as xmlmap2: " . print_r($obj, true));
        }
    }

    /** Return the object in a serialized version, in the (deprecated) "xmlmap" format. */
    public static function xmlmap_dumps(&$obj)
    {
        $chunks = array();
        self::_xmlmap_add($chunks, $obj);
        return implode('', $chunks);
    }

    /** Return the object in a serialized version, in the "xmlmap2" format. */
    public static function xmlmap2_dumps(&$obj)
    {
        $chunks = array();
        self::_xmlmap2_add($chunks, $obj, null);
        return implode('', $chunks);
    }

    private static $cache_types = array(
        #
        # OKAPI does not expose type IDs. Instead, it uses the following
        # "code words".
        #
        # IMPORTANT: For backward compatibility, cache types must never be
        # removed from this table! If a type is discarded, assign it to [].
        #
        # Changing this may introduce nasty bugs (e.g. in the replicate module).
        # CONTACT ME BEFORE YOU MODIFY THIS!
        #

        # common types of all OC sites
        'Other'        => ['oc.de' => 1, 'oc.pl' => 1],
        'Traditional'  => ['oc.de' => 2, 'oc.pl' => 2],
        'Multi'        => ['oc.de' => 3, 'oc.pl' => 3],
        'Virtual'      => ['oc.de' => 4, 'oc.pl' => 4],
        'Webcam'       => ['oc.de' => 5, 'oc.pl' => 5],
        'Event'        => ['oc.de' => 6, 'oc.pl' => 6],
        'Quiz'         => ['oc.de' => 7, 'oc.pl' => 7],
        'Moving'       => ['oc.de' => 9, 'oc.pl' => 9],

        # local types
        'Math/Physics' => ['oc.de' => 8, 'mapto' => ['name' => 'Quiz', 'acodes' => ['A16']]],
        'Podcast'      => [],
        'Drive-In'     => ['oc.de' => 10, 'mapto' => ['name' => 'Traditional', 'acodes' => ['A19']]],
        'Own'          => ['oc.pl' => 11],
        'BIT Cache'    => ['oc.pl' => 12],
        'Guestbook'    => ['oc.pl' => 13],
        'Benchmark'    => ['oc.pl' => 14],
        'Challenge'    => ['oc.pl' => 15],
    );

    /** Return all types of this OC site which are exposed by OKAPI. **/
    public static function get_local_cachetypes()
    {
        static $local_types = [];
        if (!$local_types)
        {
            $branch = Settings::get('OC_BRANCH');
            foreach (self::$cache_types as $cache_type => $properties)
                if (isset($properties[$branch]) && !isset($properties['mapto']))
                    $local_types[] = $cache_type;
        }
        return $local_types;
    }

    public static function is_local_cachetype($name)
    {
        return isset(self::$cache_types[$name][Settings::get('OC_BRANCH')]);
    }

    /** Cache types that can be searched for. **/
    public static function is_searchable_cache_type($name)
    {
        return isset(self::$cache_types[$name]);
    }

    /** E.g. 'Traditional' => 2. For unknown names throw an Exception. */
    public static function cache_type_name2id($name)
    {
        if (isset(self::$cache_types[$name][Settings::get('OC_BRANCH')]))
            return self::$cache_types[$name][Settings::get('OC_BRANCH')];
        throw new Exception("Method cache_type_name2id called with unsupported cache ".
            "type name '$name'.");
    }

    /** E.g. 2 => 'Traditional'. For unknown ids returns "Other". */
    public static function cache_type_id2name($id)
    {
        static $reversed = null;
        if ($reversed == null)
        {
            $reversed = array();
            $branch = Settings::get('OC_BRANCH');
            foreach (self::$cache_types as $name => $properties)
                if (isset($properties[$branch]))
                    $reversed[$properties[$branch]] = $name;
        }
        if (isset($reversed[$id]))
            return $reversed[$id];
        return "Other";
    }

    /** Map cache types which is not exposed by OKAPI to a common types. **/
    public static function map_cache_type($mapfrom_type)
    {
        if (isset(self::$cache_types[$mapfrom_type]['mapto']))
            return self::$cache_types[$mapfrom_type]['mapto'];
        else
            return ['name' => $mapfrom_type, 'acodes' => []];
    }

    /** Returns an array of cache types which are mapped to the given type. **/
    public static function reverse_map_cache_type($mapto_type)
    {
        static $reversed = null;
        if (!$reversed)
        {
            $reversed = array();
            $branch = Settings::get('OC_BRANCH');
            foreach (self::$cache_types as $name => $properties)
                if (isset($properties[$branch]) && isset($properties['mapto']))
                    $reversed[$properties['mapto']['name']][] = $name;
        }
        if (isset($reversed[$mapto_type]))
            return $reversed[$mapto_type];
        return [];
    }

    private static $cache_statuses = array(
        'Available' => 1, 'Temporarily unavailable' => 2, 'Archived' => 3
    );

    /** Get list of statuses available at this installation **/
    public static function get_local_statuses()
    {
        return array_keys(self::$cache_statuses);
    }

    /** E.g. 'Available' => 1. For unknown names throws an Exception. */
    public static function cache_status_name2id($name)
    {
        if (isset(self::$cache_statuses[$name]))
            return self::$cache_statuses[$name];
        throw new Exception("Method cache_status_name2id called with invalid name '$name'.");
    }

    /** E.g. 1 => 'Available'. For unknown ids returns 'Archived'. */
    public static function cache_status_id2name($id)
    {
        static $reversed = null;
        if ($reversed == null)
            $reversed = array_flip(self::$cache_statuses);
        if (isset($reversed[$id]))
            return $reversed[$id];
        return 'Archived';
    }

    private static $cache_sizes = array(
        'none' => 7,
        'nano' => 8,
        'micro' => 2,
        'small' => 3,
        'regular' => 4,
        'large' => 5,
        'xlarge' => 6,
        'other' => 1,
    );

    public static function get_local_cachesizes()
    {
        # OCPL only knows a subset of sizes, which is defined in the
        # GeoCacheCommons class. OCDE supports all sizes; they are listed
        # in the 'cache_size' table.

        if (Settings::get('OC_BRANCH') == 'oc.pl')
        {
            return ['none', 'micro', 'small', 'regular', 'large', 'xlarge'];
        }
        else
        {
            return array_keys(self::$cache_sizes);
        }
    }

    /** E.g. 'micro' => 2. For unknown names throw an Exception. */
    public static function cache_size2_to_sizeid($size2)
    {
        if (isset(self::$cache_sizes[$size2]))
            return self::$cache_sizes[$size2];
        throw new Exception("Method cache_size2_to_sizeid called with invalid size2 '$size2'.");
    }

    /** E.g. 2 => 'micro'. For unknown ids returns "other". */
    public static function cache_sizeid_to_size2($id)
    {
        static $reversed = null;
        if ($reversed == null)
            $reversed = array_flip(self::$cache_sizes);
        if (isset($reversed[$id]))
            return $reversed[$id];
        return "other";
    }

    /** Maps OKAPI's 'size2' values to opencaching.com (OX) size codes. */
    private static $cache_OX_sizes = array(
        'none' => null,
        'nano' => 1.3,
        'micro' => 2.0,
        'small' => 3.0,
        'regular' => 3.8,
        'large' => 4.6,
        'xlarge' => 4.9,
        'other' => null,
    );

    /**
     * E.g. 'micro' => 2.0, 'other' => null. For unknown names throw an
     * Exception. Note, that this is not a bijection ('none' are 'other' are
     * both null).
     */
    public static function cache_size2_to_oxsize($size2)
    {
        if (array_key_exists($size2, self::$cache_OX_sizes))
            return self::$cache_OX_sizes[$size2];
        throw new Exception("Method cache_size2_to_oxsize called with invalid size2 '$size2'.");
    }

    /**
      * Map log type names to OC internal IDs.
      *
      * Various OC sites use different English names, even for primary
      * log types. OKAPI needs to have them the same across *all* OKAPI
      * installations. That's why all known types are hardcoded here.
      * These names are officially documented and may never change!
      *
      * Important: This set is not closed. Other types may be introduced
      * in the future. Developers are advised to treat unknown types as
      * 'Comment's.
      */
    private static $submittable_log_types = [   # accepted by services/logs/submit
        'Found it' => 1,
        "Didn't find it" => 2,
        'Comment' => 3,
        'Attended' => 7,
        'Will attend' => 8,
        'Archived' => 9,   # not submittable on OCPL website, but supported and on the todo list
        'Ready to search' => 10,
        'Temporarily unavailable' => 11,
    ];
    private static $nonsubmittable_log_types = [   # not implemented in services/logs/submit
        # OCPL only
        'Moved' => 4,
        'Needs maintenance' => 5,       # submittable by type=Comment&needs_maintenance2=true
        'Maintenance performed' => 6,   # see https://github.com/opencaching/okapi/issues/548
        'OC Team comment' => 12,

        # OCDE only
        'Locked' => 13,
        # TODO:  '???' => 14,     # OCDE "Locked, invisible"; OCPL (not implemented) "Blocked" 
    ];

    public static function get_submittable_logtype_names()
    {
        return array_keys(self::$submittable_log_types);
    }

    public static function is_submittable_logtype($name)
    {
        return isset(self::$submittable_log_types[$name]);
    }

    /**
     * E.g. 'Found it' => 1. For unsupported names throws Exception.
     */
    public static function logtypename2id($name)
    {
        if (isset(self::$submittable_log_types[$name]))
            return self::$submittable_log_types[$name];
        elseif (isset(self::$nonsubmittable_log_types[$name]))
            return self::$nonsubmittable_log_types[$name];

        throw new Exception("logtypename2id called with invalid log type argument: $name");
    }

    /** E.g. 1 => 'Found it'. For unknown ids returns 'Comment'. */
    public static function logtypeid2name($id)
    {
        if ($id == 14) return "Locked";   # TODO: Assign an individual name.

        static $reverted = null;
        if (!$reverted) {
            foreach (self::$submittable_log_types as $typename => $typeid)
                $reverted[$typeid] = $typename;
            foreach (self::$nonsubmittable_log_types as $typename => $typeid)
                $reverted[$typeid] = $typename;
        }
        if (isset($reverted[$id]))
            return $reverted[$id];

        # There are lots of strange log types in OCPL databases.
        # OCPL websites ignore them or treat them as 'Comment's.

        return "Comment";
    }

    /**
     * Encodes a geocache rating - as submitted  by the user - to the "score"
     * number that will be stored in the OC database.
     */
    public static function encode_geocache_rating($rating)
    {
        # OCPL has a little strange way of storing cumulative rating. Instead
        # of storing the sum of all ratings, OCPL stores the computed average
        # and update it using multiple floating-point operations. Moreover,
        # the "score" field in the database is on the -3..3 scale (NOT 1..5),
        # and the translation made at retrieval time is DIFFERENT than the
        # one made here (both of them are non-linear). Also, once submitted,
        # there is no means to ever change the rating (but it may be added,
        # see issue #563). It surely feels quite inconsistent, but presumably
        # has some deep logic into it. See also here (Polish):
        # https://wiki.opencaching.pl/index.php/Oceny_skrzynek

        if (Settings::get('OC_BRANCH') == 'oc.pl')
        {
            switch ($rating)
            {
                case 1: return -2.0;
                case 2: return -0.5;
                case 3: return 0.7;
                case 4: return 1.7;
                case 5: return 3.0;
               default: throw new Exception();
            }
        }
        else
        {
            throw new Exception("Rating is not implemented for ".Settings::get('OC_BRANCH'));
        }
    }

    /**
     * Decodes a geocache rating "score" - as stored in the OC database -
     * to the rating number to be displayed.
     */
    public static function decode_geocache_rating($db_score)
    {
        if (Settings::get('OC_BRANCH') == 'oc.pl')
        {
            # see also SearchAssistant::prepare_common_search_params() / 'rating'
            if ($db_score >= 2.2) return 5.0;
            elseif ($db_score >= 1.4) return 4.0;
            elseif ($db_score >= 0.1) return 3.0;
            elseif ($db_score >= -1.0) return 2.0;
            else return 1.0;
        }
        else
        {
            throw new Exception("Rating is not implemented for ".Settings::get('OC_BRANCH'));
        }
    }

    /**
     * Replace outdated SITE_URLs by the current site URL. So far, this is only
     * implemented for http <-> https translation; see
     * https://github.com/opencaching/okapi/issues/508.
     */
    public static function use_current_site_url($s)
    {
        $site_url = Settings::get('SITE_URL');
        $other_scheme_url = strtr($site_url, ['http:' => 'https:', 'https:' => 'http:']);
        return str_replace($other_scheme_url, $site_url, $s);
    }

    /**
     * Returns the URL for logging in at the site.
     * $after_login is the page to display after sufcessful login.
     * $langpref is the user-supplied 'langpref' param or NULL if not supplied.
     */
    public static function oc_login_url($after_login, $langpref)
    {
        $login_url =
            Settings::get('SITE_URL') . 'login.php?' .
            'mobileView=1&' .
            'target=' . urlencode($after_login);

        if ($langpref != null) {
            # OCPL will show an error page when passing an unsupported language.
            # To be on the safe side, we pass only languages that always are available.
            # TODO: Add settings for that.

            if (Settings::get('OC_BRANCH') == 'oc.de') {
                if (in_array($langpref, ['en', 'de', 'it', 'es', 'fr']))
                    $login_url .= '&locale=' . strtoupper($langpref);
            } else {
                if (in_array($langpref, ['en', 'pl', 'nl']))
                    $login_url .= '&lang=' . $langpref;
            }
        }

        return $login_url;
    }

    /**
     * "Fix" user-supplied HTML fetched from the OC database.
     */
    public static function fix_oc_html($html, $text_html)
    {
        $html = Okapi::use_current_site_url($html);

        /* There are thousands of relative URLs in cache descriptions. We will
         * attempt to find them and fix them. In theory, the "proper" way to do this
         * would be to parse the description into a DOM tree, but that would simply
         * be very hard (and inefficient) to do, since most of the descriptions are
         * not even valid HTML.
         */

        $html = preg_replace(
            "~\b(src|href)=([\"'])(?![a-z0-9_-]+:|//)~",
            "$1=$2".Settings::get("SITE_URL"),
            $html
        );

        if (Settings::get('OC_BRANCH') === 'oc.pl' && $text_html == 1)
        {
            # Decode special OCPL entity-encoding produced by logs/submit.
            # See https://github.com/opencaching/okapi/issues/413
            # and https://github.com/opencaching/opencaching-pl/pull/1224.
            #
            # This might be restricted to log entries created by OKAPI (that are
            # recorded in okapi_submitted_objects).

            $html = preg_replace('/&amp;#(38|60|62);/', '&#$1;', $html);
        }

        /* Other things to do in the future:
         *
         * 1. Check for XSS vulnerabilities?
         * 2. Transform to a valid (X)HTML?
         */

        return $html;
    }

    /**
     * Convert strings such as "2M" or "50k" to bytes.
     */
    public static function from_human_to_bytes($val)
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        switch($last) {
            case 'g':
                return substr($val, 0, strlen($val) - 1) * 1024 * 1024 * 1024;
            case 'm':
                return substr($val, 0, strlen($val) - 1) * 1024 * 1024;
            case 'k':
                return substr($val, 0, strlen($val) - 1) * 1024;
            default:
                if (($last < '0') || ($last > '9')) {
                    throw new Exception("Unknown suffix");
                }

                return $val;
        }
    }

    /**
     * Some pages should be visible only to OKAPI developers (e.g. frequent
     * stats generation may reduce OKAPI responsiveness). This method verifies
     * that the requester is a developer. If he isn't, it die()s.
     * See also issue #524.
     */
    public static function require_developer_cookie()
    {
        if (
            (!isset($_COOKIE['okapi_devel_key']))
            || (md5($_COOKIE['okapi_devel_key']) !== '5753f318c1495c01637f7f6b7fc9c5db')
        ) {
            header("Content-Type: text/plain; charset=utf-8");
            print "I need a cookie!";
            die();
        }
    }

    /** Coordinates => "lat|lon" **/
    public static function coords2latlon($latitude, $longitude)
    {
        return
            self::float2string(round($latitude, 6)).
            "|".
            self::float2string(round($longitude, 6));
    }

    /**
     * Format a "lat|lon" location in a user-readable way;
     * returns array with latitude and longitude component.
     */
    public static function format_location_readable($location)
    {
        if (!preg_match('/^([+-]?[0-9]+(\.[0-9]*)?)\|([+-]?[0-9]+(\.[0-9]*)?)$/', $location, $matches))
            throw new Exception("invalid location format");
        return [
            self::format_coordinate($matches[1], 'N', 'S'),
            self::format_coordinate($matches[3], 'E', 'W')
        ];
    }

    private static function format_coordinate($deg, $positive_direction, $negative_direction)
    {
        $direction = ($deg < 0 ? $negative_direction : $positive_direction);
        $deg = abs($deg);
        $degrees = floor($deg);
        $minutes = ($deg - $degrees) * 60;
        # Use number_format for the float because of issue #536.
        return sprintf("%s %d° ", $direction, $degrees).number_format($minutes,3)."'";
    }

    /**
     * Take a list of "infotags" (as defined in services/apiref/method), and format
     * them for being displayed in OKAPI public documentation pages.
     */
    public static function format_infotags($infotags)
    {
        $chunks = [];
        $url = Settings::get('SITE_URL')."okapi/introduction.html#oc-branch-differences";
        foreach ($infotags as $infotag) {
            if ($infotag == "ocpl-specific") {
                $chunks[] = "<a href='$url' class='infotag infotag-ocpl-specific'>OCPL</a> ";
            } elseif ($infotag == "ocde-specific") {
                $chunks[] = "<a href='$url' class='infotag infotag-ocde-specific'>OCDE</a> ";
            }
        }
        return implode("", $chunks);
    }

    /**
     * Log temporary diagnostics data
     */
    public static function log_diagnostics($action, $comment, $consumer_key, $expire_days, $include_duplicates)
    {
        if ($include_duplicates) {
            $log = true;
        } else {
            $last = Db::select_row("
                select comment, consumer_key
                from okapi_diagnostics
                where action='".Db::escape_string($action)."'
                order by recorded_at desc
                limit 1
            ");
            $log = $last && ($comment != $last['comment'] || $consumer_key != $last['consumer_key']);
        }
        if ($log)
        {
            Db::execute("
                insert into okapi_diagnostics (action, comment, consumer_key, expires)
                values (
                    '".Db::escape_string($action)."',
                    '".Db::escape_string($comment)."',
                    '".Db::escape_string($consumer_key)."',
                    now() + interval '".Db::escape_string($expire_days)."' day
                )
            ");
        }
    }

    # object types in table okapi_submitted_objects
    const OBJECT_TYPE_CACHE = 1;
    const OBJECT_TYPE_CACHE_DESCRIPTION = 2;
    const OBJECT_TYPE_CACHE_IMAGE = 3;
    const OBJECT_TYPE_CACHE_MP3 = 4;
    const OBJECT_TYPE_CACHE_LOG = 5;           # implemented
    const OBJECT_TYPE_CACHE_LOG_IMAGE = 6;     # implemented
    const OBJECT_TYPE_CACHELIST = 7;
    const OBJECT_TYPE_EMAIL = 8;
    const OBJECT_TYPE_CACHE_REPORT = 9;
}
