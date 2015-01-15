#!/usr/bin/php -q
<?php
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//	Copyright (C) 2012 HEHE Enterprises, LLC d.b.a. i9 Technologies
//	Copyright 2013,2014 Schmooze Com Inc.

//Bootstrap FreePBX
$bootstrap_settings['freepbx_auth'] = false;
if(!@include_once(getenv('FREEPBX_CONF') ? getenv('FREEPBX_CONF') : '/etc/freepbx.conf')) {
	include_once('/etc/asterisk/freepbx.conf');
}

$ot_debug = true;
$channel = $argv[1];

$astman->SetVar($channel, "ONETOUCH_REC_SCRIPT_STATUS", "STARTED");

// Who is the person pushing Record?
$pickupExten = getVariable($channel, "PICKUP_EXTEN");
$thisExtension = getVariable($channel, "THISEXTEN");

$realCallerIdNum = getVariable($channel, "REALCALLERIDNUM");
$fromExten = getVariable($channel, "FROMEXTEN");
$mixmonid = getVariable($channel, "MIXMON_ID");
$bridgePeer = getVariable($channel, "BRIDGEPEER");
if ($fromExten == '') {
	$fromExten = $realCallerIdNum;
}

ot_debug("Checking pickup extension");
if($pickupExten != "") {
	ot_debug("Setting THISEXTEN to {$pickupExten}");
	$astman->SetVar($channel, "THISEXTEN", $pickupExten);
	$thisExtension = $pickupExten;
}

ot_debug("Checking this extension");
if($thisExtension == "") {
	ot_debug("Don't know what this exten is");
	// We don't know who we are. Let's figure it out.
	// There isn't, really, any sensible way to do this APART from just
	// looking through the DEVICE database.

	// Our device is whatever's before the - in the channl name.
	list($dev, )  = explode('-', $channel);
	ot_debug("Device is $dev");

	// However, before we look through all of them, we may (but not guaranteed
	// to be) the DIALEDPEERNUMBER.
	$dpn = getVariable($channel, "DIALEDPEERNUMBER");
	if ($astman->database_get("DEVICE/{$dpn}", "dial") == $dev) {
		// Woo! We found it!
		ot_debug("Found $dev from DIALEDPEERNUMBER as $dpn");
		$user = $astman->database_get("DEVICE/{$dpn}", "user");
		if (!$user) {
			// HOW DID THIS EVEN HAPPEN?!
			$astman->SetVar($channel, "ONETOUCH_REC_SCRIPT_STATUS", "DENIED-NOUSER");
			exit(0);
		}
		$astman->SetVar($channel, "THISEXTEN", $user);
		$thisExtension = $user;
	} else {
		// Well crap. OK. Fine. Let's get all of them then.
		$all_devices = $astman->database_show('DEVICE');
		foreach ($all_devices as $key => $dial) {
			$myvar = explode('/',trim($key,'/'));
			if (!empty($myvar[2]) && $myvar[2] == 'dial') {
				if ($dial == $dev) {
					// We found the DEVICE!
					$user = $astman->database_get("DEVICE/{$myvar[1]}", "user");
					ot_debug("We found device {$myvar[1]} to match our channel with user: $user");
					if ($user != '') {
						$thisExtension = $user;
						$astman->SetVar($channel, "THISEXTEN", $user);
						ot_debug("Changed thisExtension to $thisExtension");
						break;
					} else {
						ot_debug("No user specified, unable to track a better user to this device");
						$astman->SetVar($channel, "ONETOUCH_REC_SCRIPT_STATUS", "DENIED-NOT_LOGGED_IN");
						exit(0);
					}
				}
			}
		}
	}
}
ot_debug("This exten is $thisExtension");

//Check on demand setting for the extension
ot_debug("Checking on demand setting");
$onDemand = $astman->database_get("AMPUSER/{$thisExtension}/recording", "ondemand");
if($onDemand == "disabled") {
	ot_debug("Disabled");
	$astman->SetVar($channel, "ONETOUCH_REC_SCRIPT_STATUS", "DENIED-ASTDB");
	exit(0);
}

// Figure out who is the master channel, by looking for a RECORD_ID.
// This is created when a channel has started to record, and links
// to the master channel that is doing the recording.
$myMaster = getVariable($channel, "MASTER_CHANNEL(CHANNEL(name))");
$theirMaster = getVariable($bridgePeer, "MASTER_CHANNEL(CHANNEL(name))");
$masterChannel = false;
foreach (array($channel, $myMaster, $bridgePeer, $theirMaster) as $c) {
	$rid = getVariable($c, "RECORD_ID");
	if (!empty($rid)) {
		ot_debug("Found Master channel $rid");
		// Found it!
		$masterChannel = $rid;
		break;
	}
}

// Now, it's possible that that channel may not actually exist. If it was
// recording previously in a bridge, and recording was turned off, that
// bridge may have been removed./ Let's see if it's still there.
if ($masterChannel) {
	$test = getVariable($masterChannel, "CALLFILENAME");
	if (!$test) {
		// That channel doesn't exist. *flip tables*
		$masterChannel = false;
	}
}

if (!$masterChannel) {
	// We didn't find one. Well, I guess that means it's us.
	$masterChannel = $channel;
	foreach (array($channel, $myMaster, $bridgePeer, $theirMaster) as $c) {
		if (!empty($c)) {
			print "Setting $c with RID to $masterChannel\n";
			$astman->SetVar($c, "RECORD_ID", $masterChannel);
		}
	}
}

ot_debug("Checking if channel $masterChannel is already recording");
$rid = getVariable($masterChannel, 'RECORD_ID');
if (!empty($rid)) {
	// This channel, previously, has been recording things.
	$recStatus = getVariable($rid, 'REC_STATUS');
	if ($recStatus == "RECORDING") {
		ot_debug("Found $rid as RECORDING");
		// We're recording. Are we allowed to stop it? The CURRENT channel will have the
		// latest, correct, policy mode.
		$rpm = getVariable($channel, "REC_POLICY_MODE");
		ot_debug("RPM is $rpm in $channel");
		if ($rpm == "FORCE" && $onDemand != "override") {
			ot_debug("Denied policymode - $onDemand and $rpm");
			$astman->SetVar($channel, "ONETOUCH_REC_SCRIPT_STATUS", "DENIED-POLICYMODE");
			exit(0);
		}

		ot_debug("Stopping");
		$astman->stopmixmonitor($rid, rand());
		$astman->SetVar($rid, "REC_STATUS", "STOPPED");
		$astman->SetVar($channel, "REC_STATUS", "STOPPED");
		$astman->SetVar($bridgePeer, "REC_STATUS", "STOPPED");
		$astman->SetVar($channel, "ONETOUCH_REC_SCRIPT_STATUS", "RECORDING_STOPPED");
		exit(0);
	}
	// Ah, it's not recording. So we just want to keep that Recording ID, as we're
	// going to use it later as our master channel. However, we do know what the
	// filename should be, so we'll update that
	ot_debug("Found $rid, but it's not recording");
	$masterChannel = $rid;
	$cfn = getVariable($masterChannel, "CALLFILENAME");
	if (!empty($cfn)) {
		$callFileName = $cfn;
	}
}

$year = getVariable($channel, "YEAR");
$month = getVariable($channel, "MONTH");
$day = getVariable($channel, "DAY");
$timestr = getVariable($channel, "TIMESTR");
// It's possible that ymd may not be set. Check them all.
if (!$year) {
	$year = date("Y");
	$astman->SetVar($channel, "YEAR", $year);
}

if (!$month) {
	$month = date("m");
	$astman->SetVar($channel, "MONTH", $month);
}

if (!$day) {
	$day = date("d");
	$astman->SetVar($channel, "DAY", $day);
}

if (!$timestr) {
	$timestr = "$year$month$day-".date("His");
	$astman->SetVar($channel, "TIMESTR", $day);
}

if (!$callFileName) {
	// We need to create the filename for this call.
	$uniqueid = getVariable($channel, "UNIQUEID");
	$callFileName = "ondemand-$thisExtension-$fromExten-$timestr-$uniqueid";
	ot_debug("CFN UPDATED ::$callFileName::");
}

// It's not recording
ot_debug("Checking recording polcy {$rpm}");
if($rpm == "NEVER" && $ondemand != "override") {
	ot_debug("Recording polcy is 'never', no override, exiting");
	$astman->SetVar($channel, "ONETOUCH_REC_SCRIPT_STATUS", "DENIED-NEVER_NO_OVERRIDE");
	exit(0);
}

//Start recording the channel
ot_debug("Recording Channel");
$mixMonDir = getVariable($channel, "MIXMON_DIR");
$mixMonFormat = getVariable($channel, "MIXMON_FORMAT");
$mixMonPost = getVariable($channel, "MIXMON_POST");

// Setting in both channels in case a subsequent park or attended transfer of one
$astman->SetVar($bridgePeer, "REC_STATUS", "RECORDING");
$astman->SetVar($channel, "REC_STATUS", "RECORDING");
$astman->SetVar($channel, "AUDIOHOOK_INHERIT(MixMonitor)", "yes");
$astman->SetVar($bridgePeer, "AUDIOHOOK_INHERIT(MixMonitor)", "yes");
$astman->mixmonitor($masterChannel, "{$mixMonDir}{$year}/{$month}/{$day}/{$callFileName}.{$mixMonFormat}", "ai(LOCAL_MIXMON_ID)", $mixMonPost, rand());
$mixmonid = getVariable($channel, "LOCAL_MIXMON_ID");
$astman->SetVar($channel, "__MIXMON_ID", $mixmonid);
$channame = getVariable($channel, "CHANNEL(name)");
$astman->SetVar($channel, "__RECORD_ID", $channame);

//Set the monitor format and file name for the cdr entry
ot_debug("Setting CDR info");
$monFmt = ($mixMonFormat != "" ? $mixMonFormat : "wav");
$astman->SetVar($channel, "MON_FMT", $monFmt);
$astman->SetVar($bridgePeer, "CDR(recordingfile)", "{$callFileName}.{$monFmt}");
$astman->SetVar($channel, "CDR(recordingfile)", "{$callFileName}.{$monFmt}");

$astman->SetVar($bridgePeer, "CALLFILENAME", "{$callFileName}");
$astman->SetVar($channel, "CALLFILENAME", "{$callFileName}");

$astman->SetVar($channel, "ONETOUCH_REC_SCRIPT_STATUS", "RECORDING_STARTED");

//Get variable function
function getVariable($channel, $varName) {
	global $astman;

	$results = $astman->GetVar($channel, $varName, rand());

	if($results["Response"] != "Success"){
		return '';
	}

	return $results["Value"];
}

function ot_debug($string) {
	global $ot_debug;
	if ($ot_debug) {
		dbug($string);
		echo "$string\n";
	}
}

