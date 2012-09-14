#!/usr/bin/php -q
<?php 

// Copyright (C) 2012 HEHE Enterprises, LLC d.b.a. i9 Technologies
//
// This file is part of FreePBX.
//
// FreePBX is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 2 of the License, or
// (at your option) any later version.
//
// FreePBX is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with FreePBX.  If not, see <http://www.gnu.org/licenses/>.

//Bootstrap FreePBX
$bootstrap_settings['freepbx_auth'] = false;
if(!@include_once(getenv('FREEPBX_CONF') ? getenv('FREEPBX_CONF') : '/etc/freepbx.conf')) {
	include_once('/etc/asterisk/freepbx.conf');
}

$ot_debug = false;

ot_debug("Starting...");
$channel = $argv[1];

ot_debug("Channel: {$channel}");

//Attempt to determin the extension
ot_debug("Gathering variables");
$pickupExten = getVariable($channel, "PICKUP_EXTEN");
$callFileName = getVariable($channel, "CALLFILENAME");
$thisExtension = getVariable($channel, "THISEXTEN");
$realCallerIdNum = getVariable($channel, "REALCALLERIDNUM");
$fromExten = getVariable($channel, "FROMEXTEN");
$callFileNameParts = explode("-", $callFileName);
$callFileNameExten = $callFileNameParts[1];
$callFileNameType = $callFileNameParts[0];

ot_debug("PICKUP_EXTEN: {$pickupExten}");
ot_debug("CALLFILENAME: {$callFileName}");
ot_debug("THISEXTEN: {$thisExtension}");
ot_debug("REALCALLERIDNUM: {$realCallerIdNum}");
ot_debug("FROMEXTEN: {$fromExten}");
ot_debug("callFileNameExten: {$callFileNameExten}");
ot_debug("callFileNameType: {$callFileNameType}");

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

/*
//Check on demand setting for the extension
ot_debug("Checking on demand setting");
$extenRecordingOnDemand = $astman->database_get("AMPUSER/{$thisExtension}/recording", "ondemand");
ot_debug("AMPUSER/{$thisExtension}/recording/ondemand: {$extenRecordingOnDemand}");
if($callFileNameType == "exten" && $extenRecordingOnDemand != "enabled") {
	ot_debug("On demand setting off exiting");
	exit(0);
}
 */

//Grab the bridge peer
$bridgePeer = getVariable($channel, "BRIDGEPEER");
ot_debug("BRIDGEPEER: {$bridgePeer}");

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
if ($myMaster == $theirMaster) {
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
// --------------------------
// TODO: remove later
//Check on demand setting for the extension
ot_debug("Checking on demand setting");
$extenRecordingOnDemand = $astman->database_get("AMPUSER/{$thisExtension}/recording", "ondemand");
ot_debug("AMPUSER/{$thisExtension}/recording/ondemand: {$extenRecordingOnDemand}");
if($callFileNameType == "exten" && $extenRecordingOnDemand != "enabled") {
	ot_debug("On demand setting off exiting");
	exit(0);
}
// TODO: remove later up to here
// --------------------------

//If channel is already being recoreded if so stop the recording
ot_debug("Checking if channel is already recording");
$masterChannelOneTouchRec = getVariable($masterChannel, "ONETOUCH_REC");
ot_debug("MASTER_CHANNEL(ONETOUCH_REC): {$masterChannelOneTouchRec}");
if($masterChannelOneTouchRec == "RECORDING") {
	ot_debug("Stop recording channel");
	$astman->stopmixmonitor($channel, rand());
	// Setting in both channels in case a subsequent park or attended transfer of one
	$astman->SetVar($channel, "ONETOUCH_REC", "PAUSED");
	$astman->SetVar($bridgePeer, "ONETOUCH_REC", "PAUSED");
	$astman->SetVar($channel, "REC_STATUS", "PAUSED");
	$astman->SetVar($bridgePeer, "REC_STATUS", "PAUSED");
	if($thisExtension == "") {
		$dialPeerNumber = getVariable($channel, "DIALEDPEERNUMBER");
		ot_debug("DIALEDPEERNUMBER: {$dialPeerNumber}");
		$thisExtension = ($realCallerIdNum == "" ? $dialPeerNumber : $fromExten);
		$astman->SetVar($channel, "THISEXTEN", $thisExtension);
	}
	exit(0);
}

//If the recording poicy is never exit
ot_debug("Checking recording polcy");
$masterChannelRecPolicyMode = getVariable($masterChannel, "REC_POLICY_MODE");
ot_debug("MASTER_CHANNEL(REC_POLICY_MODE): {$masterChannelRecPolicyMode}");
if($masterChannelRecPolicyMode == "never") {
	ot_debug("Recording polcy is never exiting");
	exit(0);
}

//Check one touch recording setting
ot_debug("Checking one touch recording");
$masterChannelRecStatus = getVariable($masterChannel, "REC_STATUS");
ot_debug("MASTER_CHANNEL(REC_STATUS): {$masterChannelRecStatus}");
if($masterChannelOneTouchRec == "" && $masterChannelRecStatus == "RECORDING") {
	ot_debug("One touch recording is blank exiting");
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
ot_debug("MIXMON_DIR: {$mixMonDir}");
ot_debug("YEAR: {$year}");
ot_debug("MONTH: {$month}");
ot_debug("DAY: {$day}");
ot_debug("MIXMON_FORMAT: {$mixMonFormat}");
ot_debug("MIXMON_POST: {$mixMonPost}");

// Setting in both channels in case a subsequent park or attended transfer of one
$astman->SetVar($bridgePeer, "ONETOUCH_REC", "RECORDING");
$astman->SetVar($channel, "ONETOUCH_REC", "RECORDING");
$astman->SetVar($bridgePeer, "REC_STATUS", "RECORDING");
$astman->SetVar($channel, "REC_STATUS", "RECORDING");
$astman->SetVar($channel, "AUDIOHOOK_INHERIT(MixMonitor)", "yes");
$astman->SetVar($bridgePeer, "AUDIOHOOK_INHERIT(MixMonitor)", "yes");
$astman->mixmonitor($channel, "{$mixMonDir}{$year}/{$month}/{$day}/{$callFileName}.{$mixMonFormat}", "a", $mixMonPost, rand());
	
//Set the monitor format and file name for the cdr entry
ot_debug("Setting CDR info");
$monFmt = ($mixMonDir != "" ? $mixMonDir : "wav");
$astman->SetVar($channel, "MON_FMT", $monFmt);
$astman->SetVar($bridgePeer, "CDR(recordingfile)", "{$callFileName}.{$monFmt}");
$astman->SetVar($channel, "CDR(recordingfile)", "{$callFileName}.{$monFmt}");

// The following 2 lines are to deal with a bug in Asterisk 1.8 not setting the CDR rec file after hangup
$astman->SetVar($bridgePeer, "ONETOUCH_RECFILE", "{$callFileName}.{$monFmt}");
$astman->SetVar($channel, "ONETOUCH_RECFILE", "{$callFileName}.{$monFmt}");

//Get variable function
function getVariable($channel, $varName) {
	global $astman;

	$results = $astman->GetVar($channel, $varName, rand());

	if($results["Response"] != "Success"){
		ot_debug("Failed to get var {$varName} exiting");
		exit(1);
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

?>
