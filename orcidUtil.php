<?php

$config = parse_ini_file('orcidReport.ini');

//print usage instructions
function usage() {
	echo <<< EOF
<pre>

GET
?help                       - Print these instructions
?action=searchAffil         - Get comma-separated list of ORCID identifiers of users that may be affiliated with institution (still require validation)
?action=validateUser        - Get report information for user specified by ORCID identifier (orcidID) if they pass validation check
	&orcidID=<orcidID>
?action=ping                - Get string "PING" (to confirm application connectivity)
EOF;
	exit;
}

//call to ORCID API for base group of users that MAY be affiliated with configured institution
function affiliationQuery($start,$rows) {
	global $config;
	$searchURIBase = $config['baseURI'] . 'search/orcid-bio/?q=';
	$queryURI = $searchURIBase . $config['ringgoldID'] . '+AND+' . "'" . str_replace(' ', '+', $config['instName']) . "'&start=" . $start . "&rows=" . $rows;
	
	$result = orcidQuery($queryURI);
	return $result;
}

//call to ORCID API for specified user's full ORCID profile
function profileQuery($orcidID) {
	global $config;
	$profScope = "/orcid-profile/";
	$queryURI = $config['baseURI'] . $orcidID . $profScope;
	
	$result = orcidQuery($queryURI);
	return $result;
}

//support function that handles call to ORCID API and handles any corresponding errors
function orcidQuery($queryURI) {
	$ch = curl_init("$queryURI");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		'Content-Type: application/orcid+json'
	));
	$result = curl_exec($ch);
	
	//check success of CURL connection
	if($result === FALSE)
	{
			$errMsg = "Issue CURLing ORCID API: " . curl_error($ch) . " Attempted Query: $queryURI";
			curl_close($ch);
			errorQuit("ERR_CURL", $errMsg);
	}
	
	//check success of ORCID API call
	$queryRes = json_decode($result);
	if($queryRes != null) {
		$orcidErr = getSubProperty($queryRes,array('error-desc','value'),"None");
		if($orcidErr !== "None") {
			$errMsg = "Error with ORCID API: " . $orcidErr . " Attempted Query: $queryURI";
			errorQuit("ERR_ORCID", $errMsg);
		}
	} else {
		$errMsg = "Returned value from ORCID API cannot be decoded from JSON." . " Attempted Query: $queryURI";
		errorQuit("ERR_ORCID", $errMsg);
	}
	
	curl_close($ch);
	return $result;	
}

//batched calls to ORCID API to pull identifiers of all users that may be affiliated with configured institution
function getBaseAffiliatedUsers() {
	global $config;
	//get total count for query iteration
	$queryRes = json_decode(affiliationQuery(0,1));
	$totalCount = getSubProperty($queryRes,array('orcid-search-results','num-found'),0);
	
	//iteratively pull results in batches (size set in config file)
	$numBatches = ceil($totalCount / $config['batchSize']);
	$toReturn = "";
	for($batch = 0; $batch < $numBatches; $batch++) {
		$queryRes = json_decode(affiliationQuery(($batch*$config['batchSize']), $config['batchSize']));
		$affUsers = getSubProperty($queryRes,array('orcid-search-results','orcid-search-result'),"");
		foreach($affUsers as $user) {
				$orcid = getSubProperty($user,array('orcid-profile','orcid-identifier','path'),"");
				$toReturn .= $orcid . ',';
		}
	}
	$toReturn = chop($toReturn,',');
	return $toReturn;
}

//check that ORCID user is affiliated with institution and if so pull user information
function validateUser($orcidID) {
	global $config;
	$queryRes = json_decode(profileQuery($orcidID));
	$errorMsg = getSubProperty($queryRes,array('error-desc','value'),"None");
	if($errorMsg !== "None") {
		errorQuit("ERR_ORCID_API", $errorMsg);
	}
	$affs = getSubProperty($queryRes,array('orcid-profile','orcid-activities','affiliations','affiliation'),array());
	$latestEmplAffil = NULL;
	$latestEmplDate = "N/A";
	$latestEduAffil = NULL;
	$latestEduDate = "N/A";
	
	//cycle through affiliations to pull work/education information
	foreach($affs as $aff) {
		//if affiliation matches institutional identifier
		if(getSubProperty($aff,array('organization','disambiguated-organization','disambiguated-organization-identifier'),"") == $config['ringgoldID']) {
			//if employment affil...
			if(strcasecmp(getSubProperty($aff,'type',""),"employment") === 0) {
				$endDate = getAffilEndDate($aff);
				if($latestEmplAffil == NULL || compareAffilDates($endDate,$latestEmplDate) == 1) {
					$latestEmplAffil = $aff;
					$latestEmplDate = $endDate;
				}
			}
			//else if education affil...
			else if(strcasecmp(getSubProperty($aff,'type',""),"education") === 0) {
				$endDate = getAffilEndDate($aff);
				if($latestEduAffil == NULL || compareAffilDates($endDate,$latestEduDate) == 1) {
					$latestEduAffil = $aff;
					$latestEduDate = $endDate;
				}
			}
		}
	}
	
	//if researcher not currently affiliated with institution, return false
	if(! isset($latestEmplAffil) && ! isset($latestEduAffil)) {
		return FALSE;
	}
	
	//determine designation and title
	$designation = "";
	$title = "Unknown";
	if(isset($latestEmplAffil)) {
		$designation = "Employee";
		//trim extraneous spaces and strip out any quotation marks
		$title = str_replace('"','',trim(getSubProperty($latestEmplAffil,'role-title',"Unknown")));
	}
	if(isset($latestEduAffil)) {
		$designation .= ($designation == "") ? "Student" : ", Student";
		//if researcher is both employee and student, defer to employee title
		$title = (isset($latestEmplAffil)) ? $title : str_replace('"','',trim(getSubProperty($latestEduAffil,'role-title',"Unknown")));
	}
	
	$modDate = getSubProperty($queryRes,array('orcid-profile','orcid-history','last-modified-date','value'),"");
	//format date to be human-readable (date function expects seconds, JSON provides milliseconds, dropping last three digits to convert)
	$modDate = (strlen($modDate) >= 10) ? date('m-d-Y', substr($modDate,0,10)) : "";
	$path = getSubProperty($queryRes,array('orcid-profile','orcid-identifier','path'),"");
	$uri = getSubProperty($queryRes,array('orcid-profile','orcid-identifier','uri'),"");
	$firstName = trim(getSubProperty($queryRes,array('orcid-profile','orcid-bio','personal-details','given-names','value'),""));
	$lastName = trim(getSubProperty($queryRes,array('orcid-profile','orcid-bio','personal-details','family-name','value'),""));
	//if affil end dates are not special values, convert to easily readable format
	$latestEmplDate = ($latestEmplDate != "Unknown" && $latestEmplDate != "Present" && $latestEmplDate != "N/A") ? $latestEmplDate->format('m-d-Y') : $latestEmplDate;
	$latestEduDate = ($latestEduDate != "Unknown" && $latestEduDate != "Present" && $latestEduDate != "N/A") ? $latestEduDate->format('m-d-Y') : $latestEduDate;
	$line = $path . ',' . $uri . ',' . $modDate . ',' . $firstName . ' ' . $lastName . ',"' . $title . '","' . $designation . '",' . $latestEmplDate . ',' . $latestEduDate;
	return $line;
}

//iterate base list of loosely affiliated researchers and return information for all validated users
function getValidatedUsers() {
	$baseUserList = getBaseAffiliatedUsers();
	$users = explode('\n', $baseUserList);
	$toReturn = "";
	foreach($users as $user) {
		$affilInfo = validateUser($user);
		if($affilInfo !== FALSE) {
			$toReturn .= $affilInfo;
		}
	}
	return $toReturn;
}

//return variable if set, return default if not
function getArg($name, $def) {
	if (isset($_GET[$name])) return $_GET[$name];
	return $def; 
}

//recursively traverse ORCID property array and safely return property if it exists, default value if it doesn't
function getSubProperty($object, $propPath, $def) {
	$curObj = $object;
	if(!is_array($propPath)) {
		$propPath = array("$propPath");
	}
	foreach($propPath as $prop) {
		if(isset($curObj->{"$prop"})) {
			$curObj = $curObj->{"$prop"};
		} else {
			return $def;
		}
	}
	return $curObj;
}

//check if input value matches ORCID format
function checkOrcidFormat($id) {
	if(preg_match("/^\d{4}-\d{4}-\d{4}-\d{3}[0-9X]$/", $id) === 1) {
		return TRUE;
	}
	return FALSE;
}

//returns affil object's parsed end date (as DateTime) or special values "Present" or "Unknown" if applicable
function getAffilEndDate($affilObj) {
	$start = getSubProperty($affilObj,'start-date',NULL);
	//if no start date, end date unknown; if start date present but end date not, end is "present"
	$end = (isset($start)) ? getSubProperty($affilObj,'end-date',"Present") : "Unknown";
	//if is array, get date object, else keep special string value
	$end = ($end != "Unknown" && $end != "Present") ? getAffilDate($end) : $end;
	return $end;
}

//compares two affil dates and returns whether the first is earlier than (-1), equal to (0), or later than (1) the second
//expected date values are "Present" (highest value), actual UTC time value, and "Unknown" (for clarity's sake, considered lowest value)
function compareAffilDates($date1,$date2) {
	//present is always latest date
	if($date1 == "Present") {
		if($date2 != "Present") {
			return 1;
		} else {
			return 0;
		}
	}
	if($date2 == "Present") {
		return -1;
	}
	
	//unknown always considered "earliest" date
	if($date1 == "Unknown") {
		if($date2 != "Unknown") {
			return -1;
		} else {
			return 0;
		}
	}
	if($date2 == "Unknown") {
		return 1;
	}
	
	//general DateTime comparison
	if($date1 > $date2) {
		return 1;
	} elseif($date1 < $date2) {
		return -1;
	}
	else {
		return 0;
	}
}

//check if date string is a proper date or "Present" or "Unknown"
function validateDateString($date) {
	if($date == "Present" || $date == "Unknown" || $date instanceOf DateTime)
		return TRUE;
	
	return FALSE;
}

//takes affil date object, gets most complete date it can, and converts to DateTime before returning
function getAffilDate($dateObj) {
	if(count($dateObj) >= 1) {
		$year = getSubProperty($dateObj,array('year','value'),"");
		$month = getSubProperty($dateObj,array('month','value'),'01');
		$day = getSubProperty($dateObj,array('day','value'),'01');
		return DateTime::createFromFormat('m-d-Y', "$month-$day-$year");
	}
	return "Unknown";
}

//print error message and exit with error code
function errorQuit($eType,$msg) {
	echo $eType . " | " . $msg;
	exit(1);
}

//validate all of the util config values
function validateConfig() {
	global $config;
	//check config file load
	if(! isset($config)) {
		errorQuit("ERR_CONFIG","Could not load configuration file.");
	}
	//baseURI
	if(isset($config['baseURI']) && preg_match("~^https?://(api|pub)(\.sandbox)?\.orcid\.org/v[0-9]+\.[0-9]+(_rc[0-9])?/?$~",$config['baseURI']) === 1) {
		//ensure ending slash
		if(substr($config['baseURI'],-1) != '/') {
			$config['baseURI'] = $config['baseURI'] . '/';
		}
	} else {
		errorQuit("ERR_CONFIG","BaseURI is not valid.");
	}
	//ringgoldID
	if(!(isset($config['ringgoldID']) && preg_match("/[0-9]+/",$config['ringgoldID']) === 1)) {
		errorQuit("ERR_CONFIG","Ringgold Identifier configuration not valid.");
	}
	//instName
	if(!isset($config["instName"])) {
		errorQuit("ERR_CONFIG","Institution Name not configured.");
	}
	//batchSize
	$check = $config['batchSize'];
	if(isset($check) && is_numeric($check) && is_integer($check * 1) && ($check * 1) > 0) {
		//cast as integer
		$config['batchSize'] = $config['batchSize'] * 1;
	} else {
		errorQuit("ERR_CONFIG","Batch size configuration must be a positive, non-zero integer.");
	}
}

//validate config values and then execute action based on request arguments
validateConfig();
if (count($_GET) > 0) {
	if(getArg("help","noHelp") != "noHelp") {
		usage();
		exit;
	}
	else {
		$action	= getArg("action","");
		$orcidID = getArg("orcidID","");
		
		if($action == "searchAffil") {
			echo getBaseAffiliatedUsers();
		}
		else if($action == "validateUser") {
			//validate orcid ID format
			if($orcidID != "" && checkOrcidFormat($orcidID)) {
				$results = validateUser($orcidID);
				if($results != NULL) {
					echo $results;
				}
				else {
					echo "UNAFFILIATED_USER";
				}
			}
			else {
				errorQuit("ERR_ACTION","The validateUser action requires a valid ORCID identifier in the 'orcidID' parameter.");
			}
		}
		else if ($action == "ping") {
			echo "PING";
		}
		else {
			errorQuit("ERR_ACTION", "Invalid action provided. Use '?help' to display the supported actions.");
		}
	}
} else {
	echo "No arguments were provided. Please see usage below:";
	usage();
}

?>
