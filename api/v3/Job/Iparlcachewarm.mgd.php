<?php
// This file declares a managed database record of type "Job".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// http://wiki.civicrm.org/confluence/display/CRMDOC42/Hook+Reference
return array (
  0 => 
  array (
    'name' => 'Cron:Job.Iparlcachewarm',
    'entity' => 'Job',
    'params' => 
    array (
      'version' => 3,
      'name' => 'Reload iParl action list',
      'description' => 'Maintain an up-to-date list of iParl actions so users do not have to wait for the webhook to do this. Calls Job.Iparlcachewarm API',
      'run_frequency' => 'Hourly',
      'api_entity' => 'Job',
      'api_action' => 'Iparlcachewarm',
      'parameters' => '',
    ),
  ),
);
