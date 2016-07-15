<?php
return array (
  'iparl_webhook_key' => array(
    'group_name' => 'iParl Integration Settings',
    'group' => 'iparl',
    'name' => 'iparl_webhook_key',
    'type' => 'String',
    'title' => 'iParl webhook secret',
    'html_type' => 'text',
    'quick_form_type' => 'Element',
    'default' => '',
    'add' => '4.6',
    'is_domain' => '1',
    'is_contact' => 0,
    'description' =>  'Secret Key that iParl passes with webhook data.',
    'help_text' => 'iParl will provide this when you set up the POST Data API.',
  ),
);
