# Georgetown Orcid Report Tool
## Summary
The Georgetown ORCID Report tool is a server application that queries the ORCID API to find institution-affiliated researchers and saves the resulting reports in export-friendly CSV files. The CSV reports capture the following fields for each affiliated researcher:
* **ORCID** (the researcher's ORCID iD)
* **URL** (a URL to the researcher's ORCID profile)
* **Last Updated** (the date the researcher last made changes to their profile)
* **Name** (the full name of the researcher)
* **Title** (the title of the researcher, if available)
* **Designation** (whether the researcher is an Employee, Student, or both)
* **Empl. End** (the end date for the researcher's affiliated employment, if available)
* **Edu. End** (the end date for the researcher's affiliated education, if available)

In addition to the downloadable reports, the tool shows a dashboard summary of the researcher counts for each of the reports generated. Changes in count are dynamically calculated, and clicking on the Change number will show a table listing the new researchers for that report.
## Components
The ORCID Report tool consists of the below components:
* **orcidUtil.php** (PHP back-end interface to the ORCID API; handles connections and query processing, and must be accessible by pullOrcidData.sh)
* **pullOrcidData.sh** (Shell script that drives the ORCID report generation task; sends multiple queries to orcidUtil.php and saves the resulting reports to the filesystem)
* **orcidReport.php** (PHP access page where the reports can be downloaded and the dashboard summary viewed)
* **orcidReport.ini** (contains a number of query configuration options for the tool; used by orcidUtil.php and orcidReport.php)
* **orcidReport.js** (contains some jQuery code to support the Count table display functionality for orcidReport.php)
* **orcidReport.css** (contains styling for orcidReport.php)
## Getting Up and Running
With a little bit of customization, the ORCID Report tool can be set up to search for researchers from your institution. Since the tool doesn't do anything too fancy, any standard web server that supports PHP and Bash will likely work fine. Please note that the pullOrcidData.sh script will need to be able to curl orcidUtil.php. The following configuration values (all marked by "CHANGE ME") should be modified before attempting to use the application:
* **orcidReport.ini**
  * **instName** (set to institution's ORCID-recognized title, e.g. "Georgetown University")
  * **ringgoldID** (set to institution's Ringgold Identifier; see "Finding your Ringgold ID" below for help obtaining this value)
  * **reportDir** (set to the path to the directory that will be used to store the reports; should resolve to same as value in pullOrcidData.sh)
* **pullOrcidData.sh**
  * **UTIL_URL** (set to the URL of orcidUtil.php)
  * **ORCID_BASE_DIR** (set to the path to the base directory out of which the application can work; this directory should be created before running the script; note that the logs and reports directories will be made inside this directory)
* **orcidReport.php**
  * **orcidReport.css & orcidReport.js** (if the CSS and JS files are not located in the same directory as orcidReport.php, the link and script elements sourcing these resources will need to be updated to reflect those locations)
## Finding your Ringgold ID
If you do not know your institution's Ringgold Identifier, one possible method of obtaining it is using the ORCID API to pull the record of a known researcher associated with your institution. An example query along these lines would be "https://pub.orcid.org/v1.2/USER_ORCID_ID/orcid-profile" where "USER_ORCID_ID" is substituted with the researcher's ORCID iD. In the resulting XML, there should be a "disambiguated-organization-identifier" field in your institution's element (be sure this identifier is of type RINGGOLD). The identifier should be four-to-six digits long.
