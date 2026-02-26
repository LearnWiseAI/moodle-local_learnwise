## Release the Plugin on Moodle.org
### Step 1:Update the Plugin Version: 
1. When we release the plugin on Moodle.org we need to update the plugin version with current date. Format is {YEAR}{MONTH}{DATE}{00} so we can easily know when we released version. For example we are releasing plugin on February 24, 2026 in this case version we need to set 2026022400 ($plugin->version = 2026022400;)
2. Need to update the release with tag number like $plugin->release = '1.2.4a';

### Step 2: Log in to Moodle.org
1. Log in using the LearnWise account on Moodle.org.
2. Go to the Plugins Directory page.
3. Click on the Developer zone tab.

### Step 3: Add a New Version
1. Click Add a new version.
2. Choose one of the following release options:
   - Option 1: Download the plugin from GitHub and upload the ZIP file manually.
   - Option 2: Select the GitHub tag and click Release.
     
### Step 4: Review Version Information

1. On the next page, the plugin version information will be automatically populated from the plugin code.
2. Review the details. If needed, update the description.
3. Click Add a new version to publish the release.


### Follow the same release process for Moodle versions 3.4 to 3.8.


## Moodle Requirements 

### Moodle Version Requirements for Development

We are maintaining two separate branches to support different Moodle versions.  
For development, you must set up two separate Moodle installations:

### PHP version requirements
Here is the document where we can find PHP version requirement for Moodle versions https://moodledev.io/general/development/policies/php

### Moodle installation
Here is the documentation of Moodle installation : https://docs.moodle.org/501/en/Installing_Moodle

### Learnwise plugin Main Branch
- Supports Moodle versions 3.9 to 5.1
- Install any Moodle version within this range

### Learnwise plugin 34 Branch
- Supports Moodle versions 3.4 to 3.8
- Install any Moodle version within this range

### Plugin installation & update steps
1. Download the plugin from [Moodle plugins directory](https://moodle.org/plugins/local_learnwise) or from [GitHub](https://github.com/LearnWiseAI/moodle-local_learnwise/) repository.
2. Go to Site Administrator > Plugins > Install plugins and upload the downloaded plugin zip file

![Installation](https://github.com/LearnWiseAI/moodle-local_learnwise/raw/main/pix/installation.png)

### Enable Cron on Both Instances

Cron must be enabled on both Moodle installations for proper plugin functionality.

