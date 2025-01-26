<?php

namespace okapi\services\draftlogs\upload_fieldnotes;

use okapi\core\Exception\InvalidParam;
use okapi\core\Exception\ParamMissing;
use okapi\core\Db;
use okapi\core\Okapi;
use okapi\core\OkapiServiceRunner;
use okapi\core\Request\OkapiInternalRequest;
use okapi\core\Request\OkapiRequest;
use okapi\services\logs\LogsCommon;
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
        if (Settings::get('OC_BRANCH') != 'oc.de')
            throw new BadRequest('This method is not supported in this OKAPI installation. See the has_draftlogs field in services/apisrv/installation method.');

        $field_notes = $request->get_parameter('field_notes');
        if (!$field_notes) throw new ParamMissing('field_notes');

        // In order to understand the following, some serious explanations are in order. We are dealing here with a
        // string that resembles multiple CSV records. It is important to understand, that a line, identified by a line
        // termination character /n is not a 1:1 match withe a CSV record. In fact multiple such lines can be part of one
        // CSV record so this input variable has to treated very carefully. What complicates this further is that we cannot
        // dictate the character encoding "by design" as there are legacy applictions which have a hardcoded behaviour of
        // using UTF-16LE with no BOM. This encoding has been devised by Garmin and Groundspeak a very long time ago. More
        // modern applications use UTF-8 but we're best advised if we're tolerant to the character encoding which means
        // we must reliably detect it and convert it to UTF-8 ourselves.
        //
        // Further we accept input data as a base64 encoded string. This primarily because the OKAPI Browser (a Windows application)
        // cannot  deal with multiline string inputs, however, debugging a webservice like this is hardly possible without having
        // the OKAPI browser at hands, so we just accept the input string either plain oder base64 encoded.

        //First figure out whether it is base64 or not. If it is, decode it.

        if (self::isBase64($field_notes)) {
            $input = base64_decode($field_notes, true);
        } else {
            $input = $field_notes;
        }

        // At this point we're dealing with the plain $input string, we need to figure out the encoding and convert
        // to UTF-8. There is no single library function which proved to reliably identify the character encoding 
        // for instance  mb_detect_encoding() miserably failed identifying UTF-LE w/o BOM correctly, consequently
        // it is the safest approach to do this manually with just a few lines of code which can be understood
        // by looking at it at a glance.

        switch (true) {
            case $input[0] === "\xEF" && $input[1] === "\xBB" && $input[2] === "\xBF": // UTF-8 BOM
                $output = substr($input, 3);
                break;
            case $input[0] === "\xFE" && $input[1] === "\xFF": // UTF-16BE BOM
            case $input[0] === "\x00" && $input[2] === "\x00":
                $output = mb_convert_encoding($input, 'UTF-8', 'UTF-16BE');
                break;
            case $input[0] === "\xFF" && $input[1] === "\xFE": // UTF-16LE BOM
            case $input[1] === "\x00":
                $output = mb_convert_encoding($input, 'UTF-8', 'UTF-16LE');
                break;
            default:
                $output = $input;
        }

        // Uncomment the following line in a debug environemnt to visually inspect the $input data
        // in the final form in which we will from now on process the data. If the data doesn't
        // look right at this stage, there is no point in processing it any further as doing so
        // will inevitably fail.
        // 
        //return self::debug($request, bin2hex($output));
        
        $notes = self::parse_notes($output);
        foreach ($notes['records'] as $n)
        {
            $geocache = OkapiServiceRunner::call(
            'services/caches/geocache',
                new OkapiInternalRequest($request->consumer, $request->token, array(
                    'cache_code' => $n['code'],
                    'fields' => 'internal_id'
                ))
            );

            try {
                $type = Okapi::logtypename2id($n['type']);
            } catch (\Exception $e) {
                throw new InvalidParam('Type', 'Invalid log type provided.');
            }

            $dateString  = strtotime($n['date']);
            if ($dateString === false) {
                throw new InvalidParam('`Date` field in log record', "Input data not recognized.");
            } else {
                $date = date("Y-m-d H:i:s", $dateString);
            }

            $user_id     = $request->token->user_id;
            $geocache_id = $geocache['internal_id'];
            $text        = $n['log'];
            
            Db::query("
                insert into field_note (
                    user_id, geocache_id, type, date, text
                ) values (
                    '".Db::escape_string($user_id)."',
                    '".Db::escape_string($geocache_id)."',
                    '".Db::escape_string($type)."',
                    '".Db::escape_string($date)."',
                    '".Db::escape_string($text)."'
                )
            ");

        }

        // totalRecords is the number of parsed draft logs that were in the
        // input data. Some logs may have been discarded because they may
        // contain logs for other platforms than opencaching.de. In addition
        // to discarding "foreign" logs, we also discard logs which contain a
        // log type that is not understood by the platform.
        // As a result, processedRecords can be smaller than or equal to
        // totalRecords.

        $result = array(
            'success'          => true,
            'totalRecords'     => $notes['totalRecords'],
            'processedRecords' => $notes['processedRecords']
        );
        return Okapi::formatted_response($request, $result);
    }

    // ------------------------------------------------------------------
    // Operates on a sanitized utf-8 string of what is known as "Fieldnotes"
    // A fieldnotes are a list of CSV formatted records condensed into a
    // single string stretching across multiple "lines" where lines are marked
    // and terminated by linefeed characters \n. In its simplest form a record
    // matches a line, e.g.:
    //
    // OC1012,2023-11-27T08:27:48Z,Found it,"Thx to Retriever12 for the cache"
    //
    // This example shows that each record consist of four fields:
    // cache_code, log date, log type, and a draft log text
    //
    // What makes this challenging to parse is that the draft log can be very
    // long and it can itself contain line control characters so it stretches
    // across multiple lines in string.

    private static function parse_notes($field_notes)
    {
        $lines = self::parseCSV($field_notes);
        $submittable_logtype_names = Okapi::get_submittable_logtype_names();
        $records          = [];
        $totalRecords     = 0;
        $processedRecords = 0;

        foreach ($lines as $line) {
            $totalRecords++;
            $line = trim($line);
            $fields = str_getcsv($line);

            $code = $fields[0];
            $date = $fields[1];
            $type = $fields[2];

            if (!in_array($type, $submittable_logtype_names)) continue;

            $log  = nl2br($fields[3]);

            $records[] = [
                'code' => $code,
                'date' => $date,
                'type' => $type,
                'log'  => $log,
            ];
            $processedRecords++;
        }
        return ['success' => true, 'records' => $records, 'totalRecords' => $totalRecords, 'processedRecords' => $processedRecords];
    }


    // ------------------------------------------------------------------
    // Split lines into an array of records. Each element in the $output
    // array will then contain a string, which can strech across multiple
    // lines, each terminated with a linefeed \n.
    //
    // In this process we also skip records that will not be understood
    // by the platform, where platform is one of: geocaching.com, opencaching.{de,pl,...}
    //
    // In this function we ony take log records which start with "OC" (for opencaching.de)

    private static function parseCSV($fieldnotes)
    {
        $output = [];
        $buffer = '';
        $start = true;

        $lines = explode("\n", $fieldnotes);
        $lines = array_filter($lines); // Drop empty lines

        foreach ($lines as $line) {
            if ($start) {
                $buffer = $line;
                $start = false;
            } else {
                if (strpos($line, 'OC') !== 0) {
                    $buffer .= "\n" . $line;
                } else {
                    $output[] = trim($buffer);
                    $buffer = $line;
                }
            }
        }

        if (!$start) {
            $output[] = trim($buffer);
        }
        return $output;
    }

    // ------------------------------------------------------------------
    // Check whether a string ($s) is base64 encoded or not.

    private static function isBase64($s)
    {
        return (bool) preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $s);
    }

    // ------------------------------------------------------------------
    // This is actually a debug routine to assist in debugging the webservice
    // by generating an http response such that a php object can be visualized
    // in the absence of using functions such as var_dump() or echo.
    //
    // It could be deleted but it may be useful for debugging in case of any
    // doubts with respect to the correct function of this webservice.
    
    private static function debug($request, $debug)
    {
        $result = array('debug'=> json_encode($debug));
        return Okapi::formatted_response($request, $result);
    }
}
