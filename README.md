# iParl integration for CiviCRM

This extension works with [Organic Campaigns' iParl service](http://www.organiccampaigns.com/). That service allows people to email their MPs (MEPs, etc.).

Once set-up, people taking action through iParl will have their detailed entered
in your CiviCRM database along with an activity recording that they took action.

It requires a fair bit of set-up between your CiviCRM and your Organic Campaigns
account, including some manual "oh, would you mind setting this up please
Organic Campaigns?", "No, not a problem" sort of non-technical stuff.

## Installing the extension

You'll need a functioning iParl account before starting.

Install the extension in the normal way. A pop-up will tell you to go to
`civicrm/admin/setting/iparl` to configure things. That page has the instructions.
**You can also find this link under the Administer » iParl Settings**

You can test it like
```sh
curl -k -L 'https://yoursite.org/civicrm/iparl-webhook' \
     -d secret=helloHorseHeadLikeYourJumper \
     -d name=Jo \
     -d surname=Bloggs \
     -d email=jo@example.com \
     -d actionid=1
```

or, with httpie

```sh
http --verify no -f POST 'https://yoursite.org/civicrm/iparl-webhook' \
     secret=helloHorseHeadLikeYourJumper \
     name=Jo \
     lastname=Bloggs \
     email=jo@example.com \
     actionid=1
```

Where your-webhook-url you can get from the settings page (it's basically your
domain `/civicrm/iparl-webhook`) and your secret must match.

If it works, you should simply see `OK`. If it doesn't work, check your server
logs. If you enabled logging on the iParl extension's settings page, you'll find
the file in CiviCRM's ConfigAndLog directory. Hint: it has iparl in the name.

First time you call it you should see a new contact created. Second time you
should just see another activity added to that contact's record.

## Configuring your iParl Actions

Since version 1.3, this extension will handle single name fields, but please do
not do this. It's not possible to separate a set of names into first and last
names, better to ask users to do this; it's their personal data.

## Performance and caching

When a user submits an iParl petition, iParl sends the data to CiviCRM as a
webhook. This extension receives that data, looks up (or creates) the contact,
updates the record with an activity etc. In creating the activity, it needs to
look up the action ID sent by iParl by making another query to iParl. For some
reason (Aug 2019) this is hideously slow - near 10s - and while this is going on
the user is left waiting.

For this reason this extension caches this data (i.e. keeps its own copy). Only
when the copy is older than 1 hour will it reload. Because this is still likely
to be a problem for the poor user who stumbles upon the petition at that time, a
scheduled job runs hourly to refresh the cache, reducing the chance a user gets
hit by this.

If you have made a new petition/changed a petition you may need/want to forcibly
refresh the cache. You can do this by visiting the extension's settings page and
simply pressing Save. As well as saving the settings, it reloads the cache.

## Webhook processing

Since v1.4.0 webhooks are not processed in real time but are instead added to a
queue. The queue is processed by a new Scheduled Job. By default this scheduled
job is set up so that it runs every time Cron fires, and that it does a maximum
of 10 minutes' procesing of the iParl queue at once. This should be ample time
for most sites. You can change the schedule and the maximum execution time (in
seconds) from the Scheduled Jobs admin page.

### Warnings about failed webhooks

Sometimes iParl sends us data that is not valid for our use case. e.g.
a spammer enters a sentence about their wares into an address field and it's so
long it won't fit.

From v1.5.0 (see changelog below) these entries will be put in a new queue that
never gets processed. The iParl log file will contain details of what the problem
was from the intial processing.

If any of these are found, the System Status page will show warnings.

If you get one or two, you might choose to ignore these. If you get lots then
you will need a technical person to inspect the dedicated log file created by
this extension in the ConfigAndLog directory to see what is causing the
problems. They can then add code to handle those situations better (please
submit a Pull Request back to the project if you do make improvements), or
you can [commission me to do this work](https://artfulrobot.uk/contact).

Note that the failure could also come from outside this extension, e.g. any
custom processing you have put in place, e.g. using the provided hook, or
additional features like CiviRules.

If you intend to ignore these you can hide the System Status message in the normal way.

Technically, you will need to do one of the following (after taking a backup):

1. Delete the problem submissions by running this SQL:  
   `DELETE FROM civicrm_queue_item WHERE queue_name = 'iparl-webhooks-failed';`

2. Add the problem submissions back on the queue (e.g. if you believe they will
   work now) by running this SQL:  
   `UPDATE civicrm_queue_item WHERE SET queue_name = 'iparl-webhooks' WHERE queue_name = 'iparl-webhooks-failed';`
   (If they fail *again* then they will be recreated as a
   `iparl-webhooks-failed` record again.)


## Developers

There's now (since 1.3) a hook you can use to do your own processing of the
incoming webhook data (e.g. check/record consent and add to groups).

Example: if your custom extension is called `myext` then write a function like
this:

    /**
     * My custom business logic.
     *
     * Implements hook_civicrm_iparl_webhook_post_process
     *
     * @param array $contact The contact that the iParl extension ocreated/updated.
     * @param array $activity The activity that the iParl extension created.
     * @param array $webhook_data The raw data.
     */
    function myext_civicrm_iparl_webhook_post_process(
      $contact, $activity, $webhook_data) {

      // ... your work here ...
    }


## About

This was written by Rich Lott ([Artful Robot](https://artfulrobot.uk)) who
stitches together open source tech for people who want to change the world. It
has been funded by the Equality Trust and We Own It.

Futher pull requests welcome :-)

## Changelog

### Version 1.5.0

- Queued webhooks that cause a crash (e.g. extra long data in address fields or
  such) will no longer cause the entire queue to hang. Instead they will be
  requeued under a queue name of `iparl-webhooks-failed`. See "Warnings about
  failed webhooks" above.


### Version 1.4.0

- Processing webhooks is now deferred to a queue. This means that the user sees
  confirmation that their action has been successfully taken quicker (because
  webhooks are fired in sync and real time by iParl). It also hopes to avoid a
  deadlock situation that can occur on busy sites when two (or more) processes
  start creating Contacts, Activities etc. at the same time. Webhooks are now
  stored in a queue and a Scheduled Job is set up to process the queue. By
  ensuring only one process accesses the queue at once, this should help
  avoid deadlocks. Depending on how often cron fires, it does mean there may be
  a delay.

- tested on CiviCRM 5.15


### Version 1.3.2

- tested on CiviCRM 5.15

- iParl lookups now cached for 1 hour (you can still force a refresh by saving
  the settings form).

- New scheduled job runs hourly to keep the cache up to date.

- Settings form now moved to `civicrm/admin/setting/iparl` which is more
  standard (find it under **System Settings** in the menu)

- New hook for developers (see *Developers* below) to do more processing of
  incoming data.

- updated URLs for iParl's API for fetching titles (etc.) of actions, petitions
   (again)

### Version 1.2

- works on CiviCRM 5.9 (and possibly NOT on earlier versions)

- updated URLs for iParl's API for fetching titles (etc.) of actions, petitions.

- iParl lookups are now cached for 10 minutes; will speed up processing.
  However, if you add a new petition/action and test it immediately there's a
  chance the name won't pull through. You can force the cache to clear by
  visiting the iParl Extension's settings page (under the Administer menu)

- System status checks (Administer » Administration » System Status) now check
  for missing username/webhook key and check that the API can be used to
  download data.

- Basic phpunit tests created.

### Version 1.1

Adds support for iParl "Petition" actions (v1 just worked with "Lobby Actions").
