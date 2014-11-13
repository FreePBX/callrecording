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
	ot_debug("Setting THISEXTEN to {$callFileNameExten}");
	$astman->SetVar($channel, "THISEXTEN", $callFileNameExten);
	$thisExtension = $callFileNameExten;
}

ot_debug("Checking this extension");
if($thisExtension == "") {
	$thisExtension = ($realCallerIdNum == "" ? $callFileNameExten : $fromExten);
	ot_debug("Setting THISEXTEN to {$thisExtension}");
	$astman->SetVar($channel, "THISEXTEN", $thisExtension);
}

// Attended Transfer Issues:
//
// Testing has uncovered some cases where thisExtension ends up being derrived to the extension that
// called this extension in a scenario where the orginal caller dose an attended transfer elsewhere.
// So ... we go through the added trouble of checking if the DEVICE object for the suspected user
// matches the connected channel.
//
// If it does NOT match, we search through ALL the DEVICE dial records to see if any of them match
// this channel and if so, check their assigned user to identify who this is.
//

// Let's make sure $thisExtension matches our device
//
$device = $astman->database_get("AMPUSER/{$thisExtension}", "device");
ot_debug("Checking to make sure thisExtension is correct, Got device(s) $device");
$devices = explode('&',$device);
$channelComponents = explode('-', $channel);
$baseChannel = $channelComponents[0];
$dev_confirmed=false;
// in case mutliple devices are defined in the form of 222&322
foreach ($devices as $dev) {
	$dial = $astman->database_get("DEVICE/{$dev}", "dial");
	ot_debug("checking device $dev got dial $dial");
	if (strcasecmp($dial, $baseChannel) == 0) {
		$dev_confirmed = true;
		ot_debug("Found $dev same as $channel so we are good");
		break;
	}
}
// If we have not confirmed, let's search all the DEVICE array and see if we can find a dial string that matches
//
if (!$dev_confirmed) {
	ot_debug("thisExension $thisExtension is suspicious, checking for a better match");
	$all_devices = $astman->database_show('DEVICE');
	foreach ($all_devices as $key => $dial) {
		$myvar = explode('/',trim($key,'/'));
		if (!empty($myvar[2]) && $myvar[2] == 'dial') {
			//ot_debug("Checking DEVICE/{$myvar[1]}");
			if (strcasecmp($dial, $baseChannel) == 0) {
				// we found a DEVICE who's dialstring matches, hopefully they have a user assigned
				$user = $astman->database_get("DEVICE/{$myvar[1]}", "user");
				ot_debug("We found device {$myvar[1]} to match our channel with user: $user");
				if ($user != '') {
					$dev_confirmed = true;
					$thisExtension = $user;
					ot_debug("Changed thisExtension to $thisExtension");
					// wasn't sure if we should change it back in the channel since it could create undeterministic behavior elsewhere
					break;
				} else {
					ot_debug("No user specified, unable to track a better user to this device");
					break;
				}
			}
		}
	}
}

//Check on demand setting for the extension
ot_debug("Checking on demand setting");
$onDemand = $astman->database_get("AMPUSER/{$thisExtension}/recording", "ondemand");
if($onDemand == "disabled") {
	ot_debug("Disabled");
	$astman->SetVar($channel, "ONETOUCH_REC_SCRIPT_STATUS", "DENIED");
	exit(0);
}

//Grab the bridge peer
$myMaster = getVariable($channel, "MASTER_CHANNEL(CHANNEL(name))");
//ot_debug("myMaster: {$myMaster}");
$theirMaster = getVariable($bridgePeer, "MASTER_CHANNEL(CHANNEL(name))");
//ot_debug("theirMaster: {$theirMaster}");

// Figure out who is 'MasterChannel' - in the simplest case, there is a real
// master channel. Otherwise let's see if we've already run into this dilema
// and tagged someone with the MASERONETOUCH variable set. If not, then let's
// see which channel inheritted the ONETOUCH_RECFILE variable if any and lastly
// let's just choose 'us' if all else fails.
//
if (($myMaster == $theirMaster) && ($myMaster == $channel) || ($myMaster == $brdigePeer)) {
	// Since these agree, there was a real master channel relationship, otherwise these
	// would have just been the channel from each side of the bridge. Maybe there is
	// a more CONCLUSIVE way to deterine such a relationship???
	//
	$masterChannel = $myMaster;
	ot_debug("There is a real Master Channel: {$masterChannel}");
} else {
	$masterOneTouchUs = getVariable($channel, "MASTERONETOUCH");
	$masterOneTouchThem = getVariable($bridgePeer, "MASTERONETOUCH");
	if ($masterOneTouchUs) {
		$masterChannel = $channel;
		ot_debug("We were previsously designaged Master because of MASTERONETOUCH");
	} else if ($masterOneTouchThem) {
		$masterChannel = $bridgePeer;
		ot_debug("They were previsously designaged Master because of MASTERONETOUCH");
	} else if (getVariable($bridgePeer, "ONETOUCH_RECFILE") != '') {
		$masterChannel = $bridgePeer;
		$astman->SetVar($masterChannel, "MASTERONETOUCH", 'TRUE');
		ot_debug("No conclusive master but they have ONETOUCH_RECFILE defined so let's designate them");
	} else {
		// Either ONETOUCH_RECFILE is set for us or not, either way ... tag, we're it!
		//
		$masterChannel = $channel;
		$astman->SetVar($masterChannel, "MASTERONETOUCH", 'TRUE');
		ot_debug("No conclusive master so either we have MASTERONETOUCH defined or no one does, so we do now:)");
	}
}

$callFileName = getVariable($channel, "CALLFILENAME");

ot_debug("Checking if channel is already recording");
// New Recordings handled here. At least one of the channels we've discovered has
// a RECORD_ID, if we've ever recorded this call.
foreach (array($channel, $bridgePeer, $masterChannel) as $c) {
	$rid = getVariable($c, 'RECORD_ID');
	if (!empty($rid)) {
		// This channel, previously, has been recording things.
		$recStatus = getVariable($rid, 'REC_STATUS');
		if ($recStatus == "RECORDING") {

			// We're recording. Are we allowed to stop it?
			$rpm = getVariable($rid, "REC_POLICY_MODE");
			if ($rpm == "FORCE" && $ondemand != "override") {
				$astman->SetVar($channel, "ONETOUCH_REC_SCRIPT_STATUS", "DENIED");
				exit(0);
			}

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
		$masterChannel = $rid;
		$cfn = getVariable($masterChannel, "CALLFILENAME");
		if (!empty($cfn)) {
			$callFileName = $cfn;
		}
		break;
	}
}


if (!$callFileName) {
	// We need to create the filename for this call.
	$uniqueid = getVariable($channel, "UNIQUEID");
	$timestr = getVariable($channel, "TIMESTR");
	$callFileName = "ondemand-$thisExtension-$fromExten-$timestr-$uniqueid";
	ot_debug("CFN UPDATED ::$callFileName::");
}


// It's not recording
ot_debug("Checking recording polcy\nMASTER_CHANNEL(REC_POLICY_MODE): {$rpm}");
if($rpm == "NEVER" && $ondemand != "override") {
	ot_debug("Recording polcy is 'never', no override, exiting");
	$astman->SetVar($channel, "ONETOUCH_REC_SCRIPT_STATUS", "DENIED");
	exit(0);
}


//Start recording the channel
ot_debug("Recording Channel");
$mixMonDir = getVariable($channel, "MIXMON_DIR");
$year = getVariable($channel, "YEAR");
$month = getVariable($channel, "MONTH");
$day = getVariable($channel, "DAY");
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

