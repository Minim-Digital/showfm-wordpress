<?php
/**
 * Typed result of one API call.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What happened on one call to the show.fm API. Callers branch on the type, never on raw
 * status codes, so the rules for caching and retrying live in one place.
 */
final class Api_Result {

	/** 2xx with a JSON body. */
	const SUCCESS = 'success';

	/** 304: the cached copy is still current. */
	const NOT_MODIFIED = 'not_modified';

	/** 403 or 404 from the API: the resource is not public. Cache this and render nothing. */
	const UNAVAILABLE = 'unavailable';

	/** 401: the site key is no longer valid. The connection needs reconnecting. */
	const UNAUTHORISED = 'unauthorised';

	/** 429: wait for Retry-After seconds before calling again. */
	const RATE_LIMITED = 'rate_limited';

	/** Network error, timeout or 5xx. Keep the last good copy and back off. */
	const TRANSIENT_FAILURE = 'transient_failure';

	/** Any other response (another 4xx, a redirect, a body that is not JSON). Not cached. */
	const FAILED = 'failed';

	/**
	 * One of the type constants.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * HTTP status code, or 0 when no response arrived.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Decoded JSON body on success, else null.
	 *
	 * @var mixed
	 */
	private $data;

	/**
	 * ETag header on success or not-modified, else null.
	 *
	 * @var string|null
	 */
	private $etag;

	/**
	 * Seconds to wait before retrying (rate limited only), else 0.
	 *
	 * @var int
	 */
	private $retry_after;

	/**
	 * Short diagnostic message. Never contains a key.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Builds a result.
	 *
	 * @param string      $type        One of the type constants.
	 * @param int         $status      HTTP status code, or 0.
	 * @param mixed       $data        Decoded body on success.
	 * @param string|null $etag        ETag header.
	 * @param int         $retry_after Seconds to wait, for rate limiting.
	 * @param string      $message     Diagnostic message without secrets.
	 */
	public function __construct( string $type, int $status = 0, $data = null, ?string $etag = null, int $retry_after = 0, string $message = '' ) {
		$this->type        = $type;
		$this->status      = $status;
		$this->data        = $data;
		$this->etag        = $etag;
		$this->retry_after = $retry_after;
		$this->message     = $message;
	}

	/**
	 * Result type.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Whether the result has the given type.
	 *
	 * @param string $type One of the type constants.
	 */
	public function is( string $type ): bool {
		return $type === $this->type;
	}

	/**
	 * HTTP status code, or 0 when no response arrived.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Decoded JSON body on success, else null.
	 *
	 * @return mixed
	 */
	public function data() {
		return $this->data;
	}

	/**
	 * ETag from the response, if any.
	 */
	public function etag(): ?string {
		return $this->etag;
	}

	/**
	 * Seconds to wait before retrying, for a rate-limited result.
	 */
	public function retry_after(): int {
		return $this->retry_after;
	}

	/**
	 * Diagnostic message. Never contains a key.
	 */
	public function message(): string {
		return $this->message;
	}
}
