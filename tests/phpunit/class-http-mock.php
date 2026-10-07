<?php
/**
 * Records outgoing requests and answers them from a queue.
 *
 * @package ShowFM
 */

/**
 * Hooks `pre_http_request`. Each request is recorded; queued responses are returned in
 * order, and with an empty queue the request is blocked with a WP_Error.
 */
class ShowFM_Http_Mock {

	/**
	 * Recorded requests: url and args.
	 *
	 * @var array<int,array{url:string,args:array}>
	 */
	public $requests = array();

	/**
	 * Responses still to return.
	 *
	 * @var array<int,array|WP_Error>
	 */
	private $queue = array();

	public function __construct() {
		add_filter( 'pre_http_request', array( $this, 'handle' ), 10, 3 );
	}

	public function detach(): void {
		remove_filter( 'pre_http_request', array( $this, 'handle' ), 10 );
	}

	/**
	 * Queues a response.
	 *
	 * @param int                  $status  Status code.
	 * @param string               $body    Body.
	 * @param array<string,string> $headers Headers.
	 */
	public function respond( int $status, string $body = '', array $headers = array() ): void {
		$this->queue[] = array(
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => get_status_header_desc( $status ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Queues a network failure.
	 *
	 * @param string $message Error message.
	 */
	public function fail( string $message ): void {
		$this->queue[] = new WP_Error( 'http_request_failed', $message );
	}

	/**
	 * Filter callback.
	 *
	 * @param false|array|WP_Error $pre  Earlier short-circuit.
	 * @param array                $args Request args.
	 * @param string               $url  URL.
	 * @return array|WP_Error
	 */
	public function handle( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( empty( $this->queue ) ) {
			return new WP_Error( 'showfm_test_blocked', 'No response queued.' );
		}
		return array_shift( $this->queue );
	}

	public function count(): int {
		return count( $this->requests );
	}

	/**
	 * The most recent request.
	 *
	 * @return array{url:string,args:array}
	 */
	public function last(): array {
		return end( $this->requests );
	}
}
