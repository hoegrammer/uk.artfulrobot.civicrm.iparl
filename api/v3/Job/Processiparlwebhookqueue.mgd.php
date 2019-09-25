<?php
// This file declares a managed database record of type "Job".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
return array (
  0 => 
  array (
    'name' => 'Cron:Job.Processiparlwebhookqueue',
    'entity' => 'Job',
    'params' => 
    array (
      'version' => 3,
      'name' => 'Call Job.Processiparlwebhookqueue API',
      'description' => 'Process webhooks',
      'run_frequency' => 'Always',
      'api_entity' => 'Job',
      'api_action' => 'Processiparlwebhookqueue',
      'parameters' => 'max_time=600',
    ),
  ),
);
