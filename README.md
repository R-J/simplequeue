# Simple Queue for Vanilla Forums

This plugin provides a way for other plugins to queue time intense tasks so that forum users of Vanilla do not face inconveniences because some needed actions take longer, like sending mails, doing API calls etc.

In order for the plugin to provide functionality, you need to set up a cron job that calls "yourforum.com/plugin/simplequeue/somerandomstring" periodically. 

Here is some example code so that ou get a feeling on how to take benefit from this plugin. Let's imagine you want to mail to many, many users when something happens. While mails are sent, the user that was causing that email flood shouldn't have to wait until every mail has been send. This plugin would reduce the delay the user faces to the time that one write action to the database needs.

In order to make use of the queue, you first have to send an item to the queue. This method waits for new discussions and searches for the term "free beer". If this string can be found, a task is queued.

~~~
class FreeBeerWatcherPlugin {
    public function discussionModel_afterSaveDiscussion_handler($sender, $args) {
        if ($args['Insert'] == false) {
            return;
        }
        if (stripos(val('Body', $args['Discussion']), 'free beer') === false) {
            return;
        }
        SimpleQueuePlugin::send(
            'FreeBeerFound',
            [
                ['Body' => ['DiscussionID' => $args['DiscussionID']]]
            ]
        );
    }
~~~

`SimpleQueuePlugin::send()` takes two parameters:
1. The name of the task which will be used when the event is fired (more on that below)
2. An array of associative arrays further referenced as "messages". Each message needs to have a "Body" value and might have a "DueDate" in case you want to delay actions

The code above adds a task to the queue and when the cron job calls the endpoint, the plugin fires an event that you can hook. Events fired by the plugin have the following naming convention **Before***ValueOfFirstParameterInSendMethod*. For our example, that would be "BeforeFreeBeerFound" and since this event is fired from our class SendPulsePlugin, the code to handle it would look like that:

~~~
    public function simpleQueuePlugin_beforeFreeBeerFound_handler($sender, $args) {
        $discussionID = $args['Message']['Body']['DiscussionID'];
        $discussion = DiscussionModel::instance()->getID($discussionID);

        $users = Gdn::sql()->getWhere('User', ['Banned' => 0, 'Deleted' => 0])->resultObject();
        $messages = [];
        foreach ($users as $user) {
            $messages[] = [
                'Body' => [
                    'Recipient' => $user->Email,
                    'Text' => "Free beer, sponsored by {$discussion->InsertName}"
                ]
            ];
        }
        SimpleQueuePlugin::send('FreeBeerAlert', $messages);
        $sender->EventArguments['Acknowledged'] = true;
    }
~~~

Instead of taking an action (mailing all users) which might face a timeout, we split the main action into small parts. We started with a "mail all users" task and  break that down to a "mail user 1, mail user 2, ..." task. That would allow checking e.g. permissions and preferences, too.

Finally, when we have worked through our task it should never appear again, therefore setting "Acknowledged" to true is necessary.

After we have comfortable small chunks, we can start with the real task: sending mails:

~~~
    public function simpleQueuePlugin_beforeFreeBeerAlert_handler($sender, $args) {
        $email = new Gdn_Email();
        $email->to($args['Message']['Body']['Recipient']);
        $email->subject('Come and join!');
        $email->message($args['Message']['Body']['Text']);
        try {
            $email->send();
            $acknowledged = true;
        } catch (phpmailerException $pex) {
            $acknowledged = false;
        }
        $sender->EventArguments['Acknowledged'] = $acknowledged;
    }
}
~~~

After each successfully sent mail, don't forget to mark that this action was successful, otherwise you would send out the mails again and again.
