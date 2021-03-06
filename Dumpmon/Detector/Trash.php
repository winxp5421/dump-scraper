<?php
/**
 * @package     Dumpmon Scraper
 * @copyright   2015 Davide Tampellini - FabbricaBinaria
 * @license     GNU GPL version 3 or later
 */

namespace Dumpmon\Detector;

class Trash extends Detector
{
    private $functions;

    public function __construct()
    {
        $this->functions = array(
            'fewLines'         => 1,
            'longLines'        => 1,
            'privateKeys'      => 1,
            'antivirusDump'    => 1,
            'detectRawEmail'   => 1,
            'detectEmailsOnly' => 1,
            'detectDebug'      => 1.2,
            'detectIP'         => 1.5,
            'detectTimeStamps' => 1,
            'detectHtml'       => 1,
            'detectVarious'    => 1
        );
    }

    public function analyze($results)
    {
        foreach($this->functions as $method => $coefficient)
        {
            if(method_exists($this, $method))
            {
                $this->score += $this->$method() * $coefficient;
            }

            // I already reached the maximum value, there's no point in continuing
            if($this->score >= 3)
            {
                break;
            }
        }
    }

    protected function fewLines()
    {
        // Single lines pastebin are always trash
        if($this->lines < 3)
        {
            return 3;
        }

        return 0;
    }

    protected function antivirusDump()
    {
        $signatures = array(
            'Malwarebytes Anti-Malware',
            'www.malwarebytes.org'
        );

        foreach($signatures as $signature)
        {
            if(stripos($this->data, $signature) !== false)
            {
                return 3;
            }
        }

        return 0;
    }

    /**
     * Detect full list of email addresses only, useless for us
     */
    protected function detectEmailsOnly()
    {
        $emails = preg_match_all('/^[\s"]?[a-z0-9\-\._]+@[a-z0-9\-\.]+\.[a-z]{2,4}[\s|\t]?$/im', $this->data);

        return $emails / $this->lines;
    }

    /**
     * Files with debug info
     *
     * @return float
     */
    protected function detectDebug()
    {
        $score   = preg_match_all('/0\x[a-f0-9]{8}/i', $this->data);
        // Windows paths
        $score  += preg_match_all('#[A-Z]:\\\.*?\\\.*?\\\#m', $this->data);
        // Windows register keys
        $score  += substr_count(strtolower($this->data), 'hklm\\');
        $score  += substr_count($this->data, '#EXTINF');
        $score  += substr_count(strtolower($this->data), 'debug');
        $score  += substr_count(strtolower($this->data), '[trace]');
        $score  += substr_count(strtolower($this->data), 'session');
        $score  += substr_count(strtolower($this->data), 'class=');
        $score  += substr_count(strtolower($this->data), 'thread');
        $score  += substr_count(strtolower($this->data), 'uuid');

        // Chat log 330e8f8887e4ea04b06a6cffc66cfce0 -1 Admin Ban G-SH
        $score += preg_match_all('#[a-f0-9]{32} -\d{1}#', $this->data);

        return $score / $this->lines;
    }

    /**
     * Files with IP most likely are access log files
     */
    protected function detectIP()
    {
        $multiplier = 1;

        // Do I have a table dump? If so I have to lower the score
        $insert = substr_count($this->data, 'INSERT INTO');
        $mysql  = preg_match_all('/\+-{10,}?\+/m', $this->data);

        // Do I have lines starting with a number? Maybe it's a table dump without any MySQL markup
        $digits = preg_match_all('/^\d{1,4},/m', $this->data) / $this->lines;

        if($insert > 3 || $mysql > 5 || $digits > 0.25)
        {
            $multiplier = 0.01;
        }

        $ip = preg_match_all('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $this->data) * $multiplier;

        return $ip / $this->lines;
    }

    /**
     * Files with a lot of timestamps most likely are log files
     *
     * @return float
     */
    protected function detectTimeStamps()
    {
        $multiplier = 1;

        // Do I have a table dump? If so I have to lower the score of the timestamps, since most likely it's the creation time
        $insert = substr_count($this->data, 'INSERT INTO');
        $mysql  = preg_match_all('/\+-{10,}?\+/m', $this->data);

        // Do I have lines starting with a number? Maybe it's a table dump without any MySQL markup
        $digits = preg_match_all('/^\d{1,4},/m', $this->data) / $this->lines;

        if($insert > 3 || $mysql > 5 || $digits > 0.25)
        {
            $multiplier = 0.01;
        }

        // Mysql dates - 2015-11-02
        $dates  = preg_match_all('/(19|20)\d\d[\-\/.](0[1-9]|1[012])[\-\/.](0[1-9]|[12][0-9]|3[01])/', $this->data) * $multiplier;
        $score  = $dates / $this->lines;

        // English dates - 11-25-2015
        $dates  = preg_match_all('/(0[1-9]|1[012])[\-\/.](0[1-9]|[12][0-9]|3[01])[\-\/.](19|20)\d\d/', $this->data) * $multiplier;
        $score += $dates / $this->lines;

        // Search for the time only if the previous regex didn't match anything. Otherwise I'll count timestamps YYYY-mm-dd HH:ii:ss twice
        if($score < 0.01)
        {
            $time   = preg_match_all('/(?:2[0-3]|[01][0-9]):[0-5][0-9](?:\:[0-5][0-9])?/', $this->data) * $multiplier;
            $score += $time / $this->lines;
        }
        return $score;
    }

    /**
     * HTML tags in the file, most likely garbage
     *
     * @return float
     */
    protected function detectHtml()
    {
        // HTML tags (only the most used ones are here)
        $score  = preg_match_all('/<\/?(?:html|div|p|div|script|link|span|u|ul|li|ol|a)+\s*\/?>/i', $this->data) * 1.5;

        // Links
        $score += preg_match_all('/\b(?:(?:https?|udp):\/\/|www\.)[-A-Z0-9+&@#\/%=~_|$?!:,.]*[A-Z0-9+&@#\/%=~_|$]/i', $this->data) * 0.5;

        // Links containing an md5 hash
        $score += preg_match_all('/(?:(?:https?|udp):\/\/|www\.)[-A-Z0-9+&@#\/%=~_|$?!:,.]*[A-Z0-9+&@#\/%=~_|$]=[a-f0-9]{32}/i', $this->data);

        return $score / $this->lines;
    }

    protected function detectVarious()
    {
        $score = substr_count(strtolower($this->data), 'e-mail found');

        return $score / $this->lines;
    }

    /**
     * Files with huge lines are debug info
     *
     * @return int
     */
    protected function longLines()
    {
        // This is a special case: porn passwords usually have tons of keywords and long lines (4k+)
        // Let's manually add an exception for those files and hope for the best
        if(strpos($this->data, 'XXX Porn Passwords') !== false)
        {
            return 0;
        }

        $lines = explode("\n", $this->data);

        foreach($lines as $line)
        {
            if(strlen($line) > 1000)
            {
                return 3;
            }
        }

        return 0;
    }

    /**
     * RSA private keys
     *
     * @return int
     */
    protected function privateKeys()
    {
        if(strpos($this->data, '---BEGIN') !== false)
        {
            return 3;
        }

        return 0;
    }

    /**
     * Detects emails in "raw mode"
     *
     * @return int
     */
    protected function detectRawEmail()
    {
        if(strpos($this->data, 'Content-Type:') !== false)
        {
            return 3;
        }

        return 0;
    }
}