<?php
/**
 * Plugin that provides a queue mechanism for other plugins.
 */
class SimpleQueuePlugin extends Gdn_Plugin {
    /**
     * Init database structure changes.
     *
     * @return void.
     */
    public function setup() {
        touchConfig('SimpleQueue.FetchLimit', 100);
        touchConfig('SimpleQueue.DefaultDelay', 2);
        touchConfig('SimpleQueue.SecretUrl', betterRandomString(32, 'aA0'));
        $this->structure();
    }

    /**
     * Add table for queued jobs.
     *
     * @return void.
     */
    public function structure() {
        Gdn::structure()
            ->table('SimpleQueue')
            ->primaryKey('SimpleQueueID', 'int', 0)
            ->column('Name', 'varchar(192)', false)
            ->column('Body', 'text', false)
            ->column('DateDue', 'datetime', false)
            ->column('DateInserted', 'datetime', false)
            ->column('Acknowledged', 'tinyint(1)', false)
            ->set();
    }

    /**
     * Create a settings page for the secret url you need for cron jobs.
     *
     * @param SettingsController $sender Instance of the calling class.
     *
     * @return void.
     */
    public function settingsController_simpleQueue_create($sender) {
        $sender->permission('Garden.Settings.Manage');

        $cronUrl = url('/plugin/simplequeue/'.c('SimpleQueue.SecretUrl'), true);
        decho($cronUrl);

        $sender->setData([
            'Title' => t('Simple Queue Settings'),
            'Description' => t('You have to set up a cron job that periodically calls "'.$cronUrl.'"')
        ]);
        $configurationModule = new configurationModule($sender);
        $configurationModule->initialize([
            'SimpleQueue.SecretUrl' => [
                'Label' => 'Secret Text',
                'Description' => 'This will be a part of the url that needs to be called. Normally it doesn\'t need to be changed.'
            ]
        ]);
        $configurationModule->renderAll();
    }

    /**
     * Send a message to the queue.
     *
     * @param string $name The name of the queue.
     * @param array $messages Message must have a Body key and could have a
     *                        DateDue key.
     *
     * @return bool Whether action was successful.
     */
    public static function send(string $name, array $messages) {
        // Build one SQL query.
        $query = 'INSERT INTO '.Gdn::database()->DatabasePrefix.'SimpleQueue (Name, Body, DateDue, DateInserted, Acknowledged) VALUES ';
        $values = [];
        foreach ($messages as $message) {
            $query .= '(?, ?, ?, ?, ?),';
            array_push(
                $values,
                $name,
                dbencode($message['Body']),
                val('DateDue', $message, Gdn_Format::toDateTime()),
                Gdn_Format::toDateTime(),
                false
            );
        }
        $query = trim($query, ',');
        // Send query to db.
        try {
            Gdn::database()->query($query, $values);
            $result = true;
        } catch (Exception $ex) {
            $result = false;
        }

        return $result;
    }

    /**
     * Fetch one or more messages from the queue.
     *
     * @param integer $limit Number of messages to fetch.
     * @param array $name Name(s) of the queue item(s).
     *
     * @return array|null Array of Messages.
     */
    public function fetch(int $limit = 0, array $name = []) {
        if ($limit == 0) {
            $limit = c('SimpleQueue.FetchLimit', 10);
        }
        if ($name != []) {
            Gdn::sql()->whereIn('Name', $name);
        }

        $result = Gdn::sql()
            ->select('SimpleQueueID, Name, Body, DateDue')
            ->from('SimpleQueue')
            ->where('DateDue <=', Gdn_Format::toDateTime())
            ->where('Acknowledged', false)
            ->orderBy('DateDue', 'asc')
            ->orderBy('SimpleQueueID', 'asc')
            ->limit($limit)
            ->get()
            ->resultArray();
        foreach ($result as $key => $value) {
            $result[$key]['Body'] = dbdecode($value['Body']);
        }
        return $result;
    }

    /**
     * Mark a job as done.
     *
     * @param array $simpleQueueIDs IDs to acknowledge.
     *
     * @return bool Result of update operation.
     */
    public function acknowledge(array $simpleQueueIDs = []) {
        return Gdn::sql()
            ->update('SimpleQueue')
            ->set('Acknowledged', true)
            ->whereIn('SimpleQueueID', $simpleQueueIDs)
            ->put();
    }

    /**
     * Update "DateDue" with a later time stamp.
     *
     * @param array $simpleQueueIDs IDs to update.
     * @param integer $minutes The minutes this job should be delayed.
     *
     * @return bool Result of the operation.
     */
    public function delay(array $simpleQueueIDs = [], int $minutes = 0) {
        if ($minutes = 0) {
            $minutes = c('SimpleQueue.DefaultDelay', 2);
        }
        return Gdn::sql()
            ->update('SimpleQueue')
            ->set('DateDue', Gdn_Format::toDateTime(strtotime("+{$minutes} minutes")))
            ->whereIn('SimpleQueueID', $simpleQueueIDs)
            ->put();
    }

    /**
     * This function fires an event which must be handled by plugins.
     *
     * This function has a loop which might time out, but that is the nature
     * of such a queue.
     *
     * @param integer $jobsCount How many tasks should be processed.
     *
     * @return void.
     */
    public function run(int $jobsCount = 0) {
        // The number of jobs which should be fetched (no need for a small number).
        if ($jobsCount = 0) {
            $jobsCount = c('simpleQueue.DefaultJobsCount', 100);
        }
        // Continuously get messages
        while ($messages = $this->fetch($jobsCount)) {
            foreach ($messages as $message) {
                $acknowledged = false;
                $this->EventArguments['Acknowledged'] &= $acknowledged;
                $this->EventArguments['Message'] = $message;
                // Allow other plugins to handle messages by hooking into
                // SimpleQueuePlugin_BeforeMessageName_Handler.
                // Plugins have to set $args['Acknowledged'] = true in order to
                // mark job as done.
                $this->fireEvent('Before'.$message['Name']);
                if ($this->EventArguments['Acknowledged'] == true) {
                    $this->acknowledge([$message['SimpleQueueID']]);
                } else {
                    // If it has not been handled, it should be delayed.
                    $this->delay(
                        [$message['SimpleQueueID']],
                        c('SimpleQueue.DefaultDelay', 2)
                    );
                }
            }
        }
    }

    /**
     * Expose endpoint for cron tasks.
     *
     * @param PluginController $sender Instance of the calling class.
     * @param mixed $args Url parameters
     *
     * @return void.
     */
    public function pluginController_simpleQueue_create($sender, $args) {
        $parameter = val(0, $args, '');
        if ($parameter == '' || $parameter != c('SimpleQueue.SecretUrl')) {
            return;
        }
        $this->run(c('SimpleQueue.FetchLimit'));
    }
}
