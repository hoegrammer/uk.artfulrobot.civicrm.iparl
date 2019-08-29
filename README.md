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
`civicrm/admin/iparl` to configure things. That page has the instructions.
**You can also find this link under the Administer » iParl Settings**

You can test it like
```sh
curl -k -L 'https://your-webhook-url' \
     -d secret=helloHorseHeadLikeYourJumper \
     -d name=Jo \
     -d surname=Bloggs \
     -d email=jo@example.com \
     -d actionid=1
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
