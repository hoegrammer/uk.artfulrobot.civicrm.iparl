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

You can test it like
    curl -k -L 'https://your-webhook-url' \
        -d secret=helloHorseHeadLikeYourJumper \
        -d name=Jo \
        -d lastname=Bloggs \
        -d email=jo@exampled.com \
        -d actionid=1

Where your-webhook-url you can get from the settings page (it's basically your
domain `/civicrm/iparl-webhook`) and your secret must match.

If it works, you should simply see `OK`. If it doesn't work, check your server
logs. If you enabled logging on the iParl extension's settings page, you'll find
the file in CiviCRM's ConfigAndLog directory. Hint: it has iparl in the name.

First time you call it you should see a new contact created. Second time you
should just see another activity added to that contact's record.

## About

This was written by Rich Lott ([Artful Robot](https://artfulrobot.uk)) who
stitches together open source tech for people who want to change the world. It
was funded by the Equality Trust.

Futher pull requests welcome :-) Nb. it has to work on 4.6
