CONTENTS OF THIS FILE
---------------------

 * Introduction
 * Requirements
 * Installation
 * Configuration


INTRODUCTION
------------

Flysystem Google Cloud Storage provides a Google Cloud Storage plugin for Flysystem.
This plugin allows you to replace the local file system with Google Storage (https://console.cloud.google.com/storage).
Flysystem GCS can be used as the default file system or can be configured for each file/image field separately.


INSTALLATION
------------

Install the module and enable it according to Drupal standards.


REQUIREMENTS
------------

Need to upload Service Account Json file to your site. For this we do the following:
1. Create a project in GCP: https://cloud.google.com/resource-manager/docs/creating-managing-projects.
2. Create a Storage Bucket:
 a) Go to https://console.cloud.google.com/storage.
 b) Create a Bucket (https://cloud.google.com/storage/docs/creating-buckets).
3. Create a Service Account and get private key:
 a) Go to https://console.cloud.google.com/iam-admin/serviceaccounts.
 b) Create Service Account with Storage Admin role.
 c) Create new Key for Service Account (key type - JSON).
 d) Download the JSON file to a private folder in your site.

Flysystem GCS requires:
- Flysystem
- Flysystem Adapter for Google Cloud Storage
(https://github.com/Superbalist/flysystem-google-cloud-storage)


CONFIGURATION
------------

Stream wrappers are configured in settings.php (see the Flysystem README.md).
Example configuration:

$settings['flysystem'] = [
  'cloud-storage' => [
    'driver' => 'gcs',
    'config' => [
      'bucket' => 'example',
      'keyFilePath' => '/serviceaccount.json',
      'projectId' => 'google-project-id',
      // More options: https://googlecloudplatform.github.io/google-cloud-php/#/docs/google-cloud/v0.46.0/storage/storageclient?method=__construct.
      // Optional local configuration; see https://github.com/Superbalist/flysystem-google-cloud-storage#google-storage-specifics.
      '_localConfig' => [
        'prefix' => 'extra-folder/another-folder/',
        // Change part of URLs from https://storage.googleapis.com/[bucket_name]/extra-folder/another-folder/ to https://cname/.
        'uri' => 'https://cname',
      ],
    ],
    'cache' => true, // Cache filesystem metadata.
  ],
];

Do the following configuration changes:
- Change default download method for File system: /admin/config/media/file-system.
- Change Upload destination for existing fields.
