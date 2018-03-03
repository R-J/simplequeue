<?php

class SimpleQueuePlugin extends Gdn_Plugin {
    /**
     * Init database structure changes.
     *
     * @return void.
     */
    public function setup() {
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
     * Send a message to the queue.
     *
     * @param string $name The name of the queue.
     * @param array $messages Message must have a Body key and could have a
     *                        DateDue key.
     *
     * @return bool Whether action was successful.
     */
    public function send(string $name, array $messages) {
        // Build one SQL query.
        $query = 'INSERT INTO '.Gdn::database()->DatabasePrefix.'SimpleQueue (Name, Body, DateDue, DateInserted, Acknowledged) VALUES ';
        $values = [];
        foreach ($messages as $message) {
            $query .= '(?, ?, ?, ?),';
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
        } catch (\Exception $e) {
            $result = false;
        }

        return $result;
    }

    /**
     * Fetch one or more messages from the queue.
     *
     * @param string $name Name of the queue.
     * @param integer $limit Number of messages to fetch.
     *
     * @return array|null Array of Messages.
     */
    public function fetch($name = false, $limit = 1) {
        if ($name) {
            Gdn::sql()->where('Name', $name)
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
        foreach($result as $key => $value) {
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
    public function acknowledge(array $simpleQueueIDs) {
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
    public function delay($simpleQueueIDs, $minues = null) {
        if (!$minutes) {
            $minutes = c('simpleQueue.DefaultDelay', '5');
        }
        return Gdn::sql()
            ->update('SimpleQueue')
            ->set('DateDue', Gdn_Format::toDateTime(strtotime("+{$minutes} minutes")))
            ->whereIn('SimpleQueueID', $simpleQueueIDs)
            ->put();
    }

    /**
     * This function fires an event based on the message in the queue.
     *
     * This function has a loop which might time out
     * @param  boolean $jobsCount [description]
     * @return [type]             [description]
     */
    public function run($jobsCount = false) {
        // The number of jobs which should be fetched (no need for a small number).
        if (!$jobsCount) {
            $jobsCount = c('simpleQueue.DefaultJobsCount', 100)
        }
        // Continuously get messages
        while($messages = $this->fetch(false, $jobsCount)) {
            foreach ($messages as $message) {
                $acknowledged = false;
                $this->EventArguments['Acknowledged'] &= $acknowledged;
                $this->EventArguments['Message'] = $message;
                // Allow other plugins to handle messages by hooking into
                // SimpleQueuePlugin_MessageNameRun_Handler
                $this->fireEvent($message['Name'].'Run');
                // Plugins have to set $args['Acknowledged'] = true in order to
                // mark job as done
                if ($acknowledged) {
                    $this->acknowledge($message['SimpleQueueID']);
                } else {
                    // If it has not been handled, it should be delayed.
                    $this->delay($message['SimpleQueueID']);
                }
            }
        }
    }

    /**
     * Documentation page.
     *
     * @param SettingsController $sender Instance of the calling class.
     *
     * @return void.
     */
    public function settingsController_simplequeue_create($sender, $args) {
        $sender->render($sender->fetchViewLocation('queuedoc', '', 'plugins/simplequeue'));
    }

    public function pluginController_simpleQueue_create($handler, $args) {
        $name = 'TestQueueMessage';
        $messages[] = [
            'Body' => 'message one'
        ];
        $messages[] = [
            'Body' => 'Second Message',
            'DateDue' => Gdn_Format::toDateTime(strtotime('+30 minutes'))
        ];
        $messages[] = [
            'Body' => 'Third Message',
            'DateDue' => Gdn_Format::toDateTime(strtotime('+3 minutes'))
        ];

        // decho($this->send($name, $messages));

        decho($this->fetch('TestQueueMessage', 10));
        decho($this->fetch('TestQueueMessage'));
$message = $this->fetch('TestQueueMessage');
decho($message[0]['SimpleQueueID']);
$this->acknowledge([1]);
        decho($this->fetch('TestQueueMessage'));
        decho($this->fetch('TestQueueMessage', 10));
    }
}
