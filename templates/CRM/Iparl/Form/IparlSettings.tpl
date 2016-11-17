{* HEADER *}

<div class="crm-submit-buttons">
{include file="CRM/common/formButtons.tpl" location="top"}
</div>

<h2>How to set up your account</h2>

<p>Enter a 'secret' below. e.g. monkeyRiverRollsEyes it ought to be fairly long but avoid special characters, stick to upper/lowercase letters and numbers. Then press Save.</p>
<p>Now log into your iParl account - if you do this in a separate tab you'll be able to swap back and forth. You'll need to find your username.
On iParl's site, go to Help » Info API Help, then look at any of the URLs that look like http://www.iparl.com/api/foobar/actions - in this example <strong>foobar</strong> would be your username. You'll need to enter this (carefully!) into the box below and hit Save.</p>
<p>Finally you'll need to request that iParl send data to your webhook address which is 
<div><strong>{$webhookUrl}</strong></div>
</p>

{if $usernameUnset}
  <p><strong>Warning, your user name does not look correctly set yet.</strong></p>
{/if}


{* FIELD EXAMPLE: OPTION 1 (AUTOMATIC LAYOUT) *}

{foreach from=$elementNames item=elementName}
  <div class="crm-section">
    <div class="label">{$form.$elementName.label}</div>
    <div class="content">{$form.$elementName.html}</div>
    <div class="clear"></div>
  </div>
{/foreach}

{* FIELD EXAMPLE: OPTION 2 (MANUAL LAYOUT)

  <div>
    <span>{$form.favorite_color.label}</span>
    <span>{$form.favorite_color.html}</span>
  </div>

{* FOOTER *}
<div class="crm-submit-buttons">
{include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
