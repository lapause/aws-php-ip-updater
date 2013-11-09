<?php
namespace AwsSecurityGroupUpdater;
use \Exception;

// Environment detection
define("CLI", php_sapi_name() == "cli" || empty($_SERVER['REMOTE_ADDR']));

/**
 * AWS security groups automated updater.
 * Use it if you must access Amazon pretected resources from different locations or from an access point that does not have a fixed IP address.
 * Script will open configured port(s) for your current IP to every configured security groups.
 * Your current IP will then be saved in a storage file. On next run, if your IP changed, the script will first clean up references to the old one then add the new to the groups.
 *
 * Configuration variables can be set (by order of precedence):
 *   - By passing arguments to constructor (or command line in CLI usage)
 *   - By setting them directly in the class declaration below
 *   - By leaving them to default:
 *     - Port 22 will be opened for TCP connections
 *     - IP used will be stored in a 'lastip' file in your ~/.aws folder
 *     - You must no matter what provide at least one security group for the script to run
 *
 * Requirements
 *  - PHP >= 5.3, curl extension, not running in safe mode
 *  - AWS command line interface <http://aws.amazon.com/fr/cli>
 *
 * @author Pierre Guillaume <root@e-lixir.fr>
 * @copyright 2013 e-Lixir
 * @license MIT <http://opensource.org/licenses/mit-license.php>
 */
class AwsSecurityGroupUpdater {
   /**
    * Security group(s) to update.
    *
    * @var string|array
    */
   private $groups = array();

   /**
    * Protocol to open in security groups.
    *
    * @var string
    */
   private $protocol = "tcp";

   /**
    * Port to open in security groups.
    *
    * @var integer|string
    */
   private $port = 22;

   /**
    * Path to file used to store the last used IP address.
    *
    * @var string
    */
   private $storage = null;

   /**
    * URL to grab current IP of device. Should only return the sender IP.
    * Default to icanhazip.com, which is efficient and free.
    *
    * @static
    * @access private
    * @var string
    */
   private static $grabberUrl = "http://icanhazip.com";

   /**
    * Error returned by AWS CLI.
    *
    * @access private
    * @var string
    */
   private $error = null;

   /**
    * Create a new AwsSecurityGroupUpdater instance.
    *
    * @access public
    * @param  string|array $groups
    * @param  int|string   $port
    * @param  string       $protocol
    * @param  string       $storage
    * @param  string       $grabberUrl
    * @return void
    */
   public function __construct($groups = array(), $port = null, $protocol = null, $storage = null, $grabberUrl = null) {
      // Sanity checks
      if(empty($groups) && empty($this->groups))
         $error = "No security group provided";
      if($groups)
         $this->groups = $groups;
      if(!$port && !$this->port)
         $error = "No port provided";
      if($port && !(int) $port)
         $error = "Port must bean integer";
      if($protocol && !in_array($protocol, $protocols = array("tcp", "udp", "icmp")))
         $error = "Protocol must be one of the following: " . implode(", ", $protocols);
      if(!$storage && !$this->storage)
         $this->storage = getenv("HOME") . "/.aws/lastip";
      if(!$grabberUrl && !static::$grabberUrl)
         $error = "No IP grabber URL provided";
      if(!empty($error))
         static::usage();
      $this->error($error);
      if(!extension_loaded("curl"))
         $this->error("cURL extension must be installed in order for AwsSecurityGroupUpdater to work");
      if(!is_dir(getenv("HOME") . "/.aws"))
         $this->error("'~/.aws directory is missing. AWS CLI don't seem to be installed");

      // Initialization
      if($port)
         $this->port = $port;
      if($protocol)
         $this->protocol = $protocol;
      if($storage)
         $this->storage = $storage;
      if($grabberUrl)
         static::$grabberUrl = $grabberUrl;
      $this->groups = (array) $groups;
   }

   /**
    * Create a new AwsSecurityGroupUpdater instance and perform update.
    *
    * @static
    * @access public
    * @param  string|array $groups
    * @param  int|string   $port
    * @param  string       $protocol
    * @param  string       $storage
    * @param  string       $grabberUrl
    * @return void
    */
   public static function update($groups = array(), $port = null, $protocol = null, $storage = null, $grabberUrl = null) {
      $updater = new static($groups, $port, $protocol, $storage, $grabberUrl);
      return $updater->updateGroups();
   }

   /**
    * If in CLI context, display an error message and stop script execution. Otherwise, throw an exception.
    *
    * @access private
    * @param  string  $message
    * @return void
    * @throws AwsSecurityGroupUpdaterException
    */
   public function error($message) {
      if(CLI) {
         $fp = fopen("php://stderr", "w");
         fwrite($fp, $message . PHP_EOL . PHP_EOL);
         if($this->error)
            fwrite($fp, "Details" . PHP_EOL . $this->error . PHP_EOL . PHP_EOL);
         fclose($fp);
         exit(1);
      }
      else throw new AwsSecurityGroupUpdaterException($message);
   }

   /**
    * If in CLI context, display a message.
    *
    * @access private
    * @param  string  $message
    * @param  bool    $cr      Add a carriage return after message?
    * @param  string  $style   normal | underline | green | red
    * @return void
    */
   private function msg($message = "", $cr = true, $style = "normal") {
      if(!CLI)
         return;
      $flag = in_array($message, array("ok", "nok", "n/a"));
      if($style == "underline")
         $message = "\x1b[4m" . $message . "\x1b[0m";
      elseif($message == "ok" || $style == "green")
         $message = "\x1b[32;1m" . $message . "\x1b[0m";
      elseif(in_array($message, array("nok", "n/a")) || $style == "red")
         $message = "\x1b[31;1m" . $message . "\x1b[0m";
      if($flag)
         $message = "[ " . $message . " ]";
      $fp = fopen("php://stdout", "w");
      fwrite($fp, $message . ($cr ? PHP_EOL : ""));
      fclose($fp);
   }

   /**
    * Grab current IP of the device.
    *
    * @access public
    * @return string
    */
   public function ip() {
      try {
         $ch = curl_init();
         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
         curl_setopt($ch, CURLOPT_URL, static::$grabberUrl);
         $ip = curl_exec($ch);
      }
      catch(\Exception $e) {
         $this->error("Unable to grab current IP address");
      }
      if(!preg_match("`^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$`", $ip))
         $this->error("The IP grabber URL returned an unexpected response");
      return $ip;
   }

   /**
    * Return CLI usage.
    *
    * @static
    * @access public
    * @return void
    */
   public static function usage() {
      if(!CLI)
         return;
      $fp = fopen("php://stderr", "w");
      fwrite($fp, "Usage: php " . $_SERVER['SCRIPT_NAME'] . " -g GROUP1 [-g GROUP2...] [-p PORT] [-t PROTOCOL] [--grabber URL] [--storage FILE]" . PHP_EOL);
      fclose($fp);
   }

   /**
    * Execute an AWS CLI command, an return the result parsed.
    *
    * @access private
    * @param  string  $cmd  EC2 command to execute
    * @param  array   $args Command line arguments
    * @return object|array
    * @throws AwsSecurityGroupUpdaterException
    */
   private function exec($cmd, $args = array()) {
      $cmd = call_user_func_array("sprintf", array_merge(array("aws ec2 " . $cmd), array_map("escapeshellarg", $args)));
      try {
         $proc = proc_open($cmd, array(1 => array("pipe", "w"), 2 => array("pipe", "w")), $pipes);
         if(is_resource($proc)) {
            $res = stream_get_contents($pipes[1]);
            $this->error = stream_get_contents($pipes[2]);
            $ret = proc_close($proc);
            if($ret && $ret != 255)
               $this->error("An error occured during EC2 command execution");
         }
      }
      catch(\Exception $e) {
         $this->error("An error occured during EC2 command execution");
      }
      try {
         $res = json_decode($res);
      }
      catch(\Exception $e) {
         $this->error("Unable to parse AWS response in JSON");
      }
      return $res;
   }

   /**
    * Update security groups.
    *
    * @access private
    * @return void
    * @throws AwsSecurityGroupUpdaterException
    */
   private function updateGroups() {
      // We display handled groups
      $this->msg("Groups:\t\t\t", false, "underline");
      $this->msg(implode(", ", $this->groups));

      // We grab IP addresses
      if(file_exists($this->storage))
         $old = trim(file_get_contents($this->storage));
      else $old = null;
      $ip = $this->ip() . "/32";
      $this->msg("Your old IP:\t\t", false, "underline");
      if($old)
         $this->msg($old);
      else $this->msg("<not available>");
      $this->msg("Your current IP:\t", false, "underline");
      $this->msg($ip);
      $this->msg();

      // Same IP, nothing to do
      if($old == $ip) {
         $this->msg("Nothing to do.");
         $this->msg();
         return;
      }

      // If IP has changed, we remove the old one
      if($old && $old != $ip) {
         $this->msg("Old IP references cleanup", true, "underline");
         $filters = sprintf("Name=ip-permission.to-port,Values=%s,Name=ip-permission.protocol,Values=%s,Name=ip-permission.cidr,Values=%s", $this->port, $this->protocol, $old);
         $refs = $this->groups;
         $groups = $this->exec("describe-security-groups --group-names" . str_repeat(" %s", count($this->groups)) . " --filters %s", array_merge($this->groups, array($filters)));
         foreach($groups->SecurityGroups as $group) {
            unset($refs[array_search($group->GroupName, $refs)]);
            $this->msg("Removal from " . sprintf("%-30s", $group->GroupName . "..."), false);
            $this->exec("revoke-security-group-ingress --group-id %s --protocol %s --port %s --cidr %s", array($group->GroupId, $this->protocol, $this->port, $old));
            $this->msg("ok");
         }
         foreach($refs as $ref) {
            $this->msg("Removal from " . sprintf("%-30s", $ref . "..."), false);
            $this->msg("n/a");
         }
      }
      else $this->msg("Nothing to cleanup");
      $this->msg();

      // We add new IP to security groups
      $this->msg("Adding current IP to security groups", true, "underline");
      $groups = $this->exec("describe-security-groups --group-names" . str_repeat(" %s", count($this->groups)), $this->groups);
      foreach($groups->SecurityGroups as $group) {
         $this->msg("Adding to " . sprintf("%-33s", $group->GroupName . "..."), false);
         $this->exec("authorize-security-group-ingress --group-id %s --protocol %s --port %s --cidr %s", array($group->GroupId, $this->protocol, $this->port, $ip));
         $this->msg("ok");
      }
      $this->msg();

      // We update last IP storage
      try {
         file_put_contents($this->storage, $ip);
      }
      catch(\Exception $e) {
         throw new AwsSecurityGroupUpdaterException("Unable to write current IP to storage file");
      }
   }
}

/**
 * AwsSecurityGroupUpdater exception class.
 */
class AwsSecurityGroupUpdaterException extends Exception {}

/**
 * CLI usage
 */
if(CLI && pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_BASENAME) == pathinfo(__FILE__, PATHINFO_BASENAME)) {
   $groups = array();
   $port = $protocol = $storage = $grabberUrl = null;
   foreach(getopt("g:p:t:", array("grabber", "storage")) as $k => $v) {
      if($k == "g")
         $groups[] = $v;
      elseif($k == "p")
         $port = $v;
      elseif($k == "t")
         $protocol = $v;
      elseif($k == "grabber")
         $grabberUrl = $v;
      elseif($k == "storage")
         $storage = $v;
   }
   AwsSecurityGroupUpdater::update($groups, $port, $protocol, $storage, $grabberUrl);
}
