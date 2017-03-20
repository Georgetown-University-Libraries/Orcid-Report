#!/bin/bash

UTIL_URL="<CHANGE ME>"
ORCID_BASE_DIR="<CHANGE ME>"
REPORT_DIR="${ORCID_BASE_DIR}/reports"
LOGS_DIR="${ORCID_BASE_DIR}/logs"
WORKING_FILE=$(mktemp ${ORCID_BASE_DIR}/orcidReport.XXXXXXXX.csv)
read Y m d H M <<< $(date "+%Y %m %d %H %M")
OUTPUT_REPORT="${REPORT_DIR}/orcidUsers_${Y}-${m}-${d}_${H}-${M}"
OUTPUT_FORMAT=".csv"
JOB_LOG="${LOGS_DIR}/orcidPull_${Y}-${m}-${d}_${H}-${M}.log"


#Queries ORCID util to determine if user (provided via ORCID ID parameter) meets affiliation criteria. Returns user information if affiliated, empty string if not
function validateUser {
	userID=$1
	echo $(curl -s "${UTIL_URL}?action=validateUser&orcidID=${userID}")
}

function cleanup {
	rm -f $WORKING_FILE
}

#Print to both stdout and log file (meant for cron to capture stdout and send job report via mailto)
function jobPrint {
	echo "$1"
	echo "$1" >> $JOB_LOG
}

#Exit script with provided error description
function errorQuit {
	jobPrint "ERR_SCRIPT|$1"
	jobPrint "Ending script with exit status 1"
	cleanup
	exit 1
}

trap cleanup SIGHUP SIGINT SIGTERM

jobPrint "===Orcid Report Data Pull on $(date)==="

jobPrint "Validating reports directory -- $(date +%T)"
#ensure reports/logs directory exists
mkdir -p $REPORT_DIR
if [[ $? -ne 0 ]]; then
	errorQuit "Cannot find/create reports directory. Please ensure that directory location is writable."
fi
mkdir -p $LOGS_DIR
if [[ $? -ne 0 ]]; then
	errorQuit "Cannot find/create logs directory. Please ensure that directory location is writable."
fi

jobPrint "Validating application connection -- $(date +%T)"
#test app connection
checkCon=$(curl -s "$UTIL_URL/?action=ping")
if [[ "$checkCon" != "PING" ]]; then
	errorQuit "Cannot curl $UTIL_URL. Please verify web server allows communication to this resource."
fi

jobPrint "Pulling base list of potentially affiliated users -- $(date +%T)"
#pull base affiliated users list
curl -s "$UTIL_URL/?action=searchAffil" > $WORKING_FILE
read checkCode <<< $(cat $WORKING_FILE)
if [[ "${checkCode:0:3}" == "ERR" ]]; then
	errorQuit "Could not pull base list due to following error: $checkCode"
fi

jobPrint "Validating $(cat $WORKING_FILE | tr "," "\n" | wc -l) potentially affiliated users -- $(date +%T)"
#validate each ORCID user listed in the working file and print results to masterlog
echo "ORCID,URL,Last Updated,Name,Title,Designation,Empl. End, Edu. End" > ${OUTPUT_REPORT}${OUTPUT_FORMAT}
matchCount=0
errorCount=0
for i in $(cat $WORKING_FILE | tr "," "\n"); do
	userInfo=$(validateUser $i)
	if [[ "${userInfo:0:3}" == "ERR" ]]; then
		echo "${userInfo} ORICD: $i" >> $JOB_LOG
		((errorCount++))
	elif [[ "$userInfo" != "UNAFFILIATED_USER" ]]; then
		echo $userInfo >> ${OUTPUT_REPORT}${OUTPUT_FORMAT}
		((matchCount++))
	fi
done
#add user count to end of report name
mv "${OUTPUT_REPORT}${OUTPUT_FORMAT}" "${OUTPUT_REPORT}x${matchCount}${OUTPUT_FORMAT}"
jobPrint "Finished. Found $matchCount users matching affiliation criteria -- $(date +%T)"

if [[ $errorCount -gt 0 ]]; then
	echo "WARNING: Encountered $errorCount errors during user validation. See job log for full details."
	echo "WARNING: Encountered $errorCount errors during user validation. Accompanying base user list called errorBaseList__${Y}-${m}-${d}.csv" >> $JOB_LOG
	cp $WORKING_FILE ${ORCID_LOGS_DIR}/errorBaseList__${Y}-${m}-${d}.csv
fi

jobPrint "Cleaning temporary files"
cleanup

jobPrint "===End Data Pull on $(date)==="