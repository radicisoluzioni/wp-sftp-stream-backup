<?php
/**
 * Unrecoverable job error: the job fails immediately instead of retrying.
 *
 * @package WPSSB
 */

namespace WPSSB\Job;

class FatalJobException extends \RuntimeException {
}
