<?php

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

global $db;

$autoincrement = (($amp_conf["AMPDBENGINE"] == "sqlite") || ($amp_conf["AMPDBENGINE"] == "sqlite3")) ? "AUTOINCREMENT":"AUTO_INCREMENT";
$sql[]="CREATE TABLE IF NOT EXISTS callrecording (
	callrecording_id INTEGER NOT NULL PRIMARY KEY $autoincrement,
	callrecording_mode VARCHAR( 50 ) ,
	description VARCHAR( 50 ) ,
	dest VARCHAR( 255 )
)";
$sql[]="CREATE TABLE IF NOT EXISTS callrecording_module (
			extension varchar(50),
			cidnum varchar(50) default '',
      callrecording varchar(10),
      display varchar(20)
			);";

foreach($sql as $s){
	$check = $db->query($s);
	if(DB::IsError($check)) {
		die_freepbx("Can not create callrecording table\n");
	}
}

$freepbx_conf = freepbx_conf::create();
// Play 'beep' while recording
$set['value'] = '';
$set['defaultval'] =& $set['value'];
$set['options'] = array(1,300);
$set['readonly'] = 0;
$set['hidden'] = 0;
$set['level'] = 1;
$set['module'] = 'callrecording';
$set['category'] = 'Call Recording';
$set['emptyok'] = 1;
$set['sortorder'] = 10;
$set['name'] = "Beep every n seconds";
$set['description'] = "Asterisk 13.2 and higher supports the ability to play a regular 'beep' when a call is being recorded. If you set this to a positive number value, when a call is being actively recorded, both parties will hear a 'beep' every period that you select. If you are not running Asterisk 13.2 or higher, this setting will have no effect. To disable simply clear the value of this box and save. This is typically set arround 15seconds";
$set['type'] = CONF_TYPE_INT;
$freepbx_conf->define_conf_setting('CALLREC_BEEP_PERIOD',$set);
