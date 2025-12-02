# Moodle LearnWise Plugin

This plugin allows you to set up and configure the LearnWise AI assistant (https://www.learnwise.ai/) for your Moodle site. Learn more about LearnWise at https://www.learnwise.ai/how-it-works.

Note that this plugin requires an existing organizational account within the LearnWise platform. Want to learn more about LearnWise? Please get in touch with us at https://www.learnwise.ai/demo.


## Requirements
- Moodle 3.9 or Higher
- PHP 7.4 or Higher
- Access to the LearnWise Admin Panel
- Site administrator privileges in your Moodle environment

## Installation steps
1. Download the plugin from [Moodle plugins directory](https://moodle.org/plugins/local_learnwise) or from [GitHub](https://github.com/LearnWiseAI/moodle-local_learnwise/) repository.
2. Go to Site Administrator > Plugins > Install plugins and upload the downloaded plugin zip file

![Installation](https://github.com/LearnWiseAI/moodle-local_learnwise/raw/main/pix/installation.png)

## Configure the Plugin in Moodle
1. Go to Site Administration > Server > LearnWise Integration.
2. Select “Production” as your environment.

![Configuration](https://github.com/LearnWiseAI/moodle-local_learnwise/raw/main/pix/environment.png)

## 1. Option A: Floating Support Button Integration
1. Locate your **Assistant ID** in the Publish > Configure LTI> Moodle window
2. Paste the Assistant ID into the corresponding field in Moodle.
3. Select the region.
4. Enter the Course IDs comma separated (optional) to load the chat on specific courses.
5. Click **Save**.
6. The floating LearnWise button will now appear on the bottom-right corner of your Moodle interface.
![Floatingbuttion](https://github.com/LearnWiseAI/moodle-local_learnwise/raw/main/pix/floatingbutton.png)

## 2. Option B: LTI Course Assistant Integration
1. Enable LTI toggle in Moodle
2. From the Moodle LTI setup screen, copy the following:
    - **Platform ID**
    - **Client ID**
    - **Deployment ID**
      ![LTI](https://github.com/LearnWiseAI/moodle-local_learnwise/raw/main/pix/lti.png)

3. Paste these into the appropriate fields in the LearnWise wizard located on the Publish > LTI Connection > Moodle channel
4. Click Next, then Submit to create the LTI connection.
   ![Learnwise-config](https://github.com/LearnWiseAI/moodle-local_learnwise/raw/main/pix/learnwiseconfig.png)

## 3. Ingest Moodle Course Content (Optional)

To enable the assistant to interact with course-specific material:
1. In the LearnWise Admin Panel, go to the **“Knowledge”** tab.
2. Click **Courses > Moodle > Connect**.

![learnwise-course.png](https://github.com/LearnWiseAI/moodle-local_learnwise/raw/main/pix/learnwise-course.png)

1. In Moodle:
    - Go to **Site Administration > Server > LearnWise Integration**.
    - **Enable web service for course content ingestion** in Moodle
2. Copy your **Platform ID** and **Access Token**.

![course-consent](https://github.com/LearnWiseAI/moodle-local_learnwise/raw/main/pix/course-consent.png)

1. Paste them into the connection window in LearnWise & select which course content you would like to ingest form the list of content types
2. Your assistant is now ready set-up to ingest course content

![learnwise-token](https://github.com/LearnWiseAI/moodle-local_learnwise/raw/main/pix/learnwise-token.png)

## 4. Enable Live API Integration (Optional)

To pull live Moodle data (user role,  assignments, etc.):

1. Enable the **Live API Integration** toggle in Moodle
2. Copy the **Client ID** and **Secret** from your Moodle

![API integration.png](https://github.com/LearnWiseAI/moodle-local_learnwise/raw/main/pix/integration.png)

1. Paste them into your LearnWise dashboard and click **Verify**.
2. If successful, you’ll be redirected to Moodle to confirm authorization.

![Learnwise-API](https://github.com/LearnWiseAI/moodle-local_learnwise/raw/main/pix/learnwise-api.png)

## 5. AI Assessment (Optional)

This allows the assistant to assist in assignment grading

![AI-Assessment](https://github.com/LearnWiseAI/moodle-local_learnwise/raw/main/pix/ai-assessment.png)


## 6. Launch the Assistant in Moodle Courses

1. In Moodle, navigate to any course where you want to add the assistant.
2. Choose **Add an activity or resource**
3. Select **LearnWise** from the list of external tools.
   (If it is not listed, ensure it is toggled on as an option under LTI external tools)
4. Save and return to course.
