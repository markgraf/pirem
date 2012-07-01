<?php
//if (php_sapi_name()!=="cli") die("CLI-access only");

//grab class
include('wikibot.php');
// Config File
$configFile = "/etc/pirem/pirem.conf";
// Workaround for non-root installation
if (!is_file($configFile)) $configFile = "../../../etc/pirem/pirem.conf";

if (!is_file($configFile)) die("Config File not found.");
$settings = parse_ini_file($configFile);

//get password
$username = $settings["WIKIUSERNAME"];
$password = $settings["WIKIUSERPASSWORD"];

$wikiurl = $settings["WIKIURL"];
$artikelurl = $settings["WIKIARTICLEURL"];

$vorlage["datum"] = $settings["WIKIDATETEMPLATE"];
$vorlage["zeit"] =  $settings["WIKITIMETEMPLATE"];
$vorlage["ort"] = $settings["WIKIPLACETEMPLATE"];

$adminmail = $settings["WIKIDEBUGADMINMAIL"];

$botpassword = $settings["WIKIBOTPASSWORD"];
