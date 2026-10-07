"""Central error catalogue for the backup engine.

Every driver/module raises `BackupError` with one of the codes below instead
of a bare string scattered inline. The codes themselves are NOT renamed from
what already existed before ENGINE-1 (see docs/ENGINE_DRIVERS.md for why) —
this module just gives them one home instead of duplicating the class
definition across `drivers/mikrotik_ssh.py`, `storage.py`, `ftp_incoming.py`
and `ftp_spontaneous.py`. `App\\Services\\EngineJobService::fail()` on the
Laravel side keeps its own PT-BR message map keyed by these same strings —
this is the Python-side source of truth for which codes exist and whether
they are safe to retry automatically (`is_retryable`).
"""


class BackupError(Exception):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


# Dispatch / policy eligibility (raised by the engine before a driver runs)
ENGINE_FAILED = 'ENGINE_FAILED'
UNSUPPORTED_VENDOR = 'UNSUPPORTED_VENDOR'
UNSUPPORTED_POLICY = 'UNSUPPORTED_POLICY'
FTP_ACCOUNT_UNAVAILABLE = 'FTP_ACCOUNT_UNAVAILABLE'
CREDENTIAL_INVALID = 'CREDENTIAL_INVALID'

# SSH transport — shared by every SSH-based driver (MikroTik, Huawei VRP, and
# any future SSH driver should raise these same codes rather than inventing
# vendor-specific equivalents).
SSH_CONNECT_FAILED = 'SSH_CONNECT_FAILED'
SSH_TIMEOUT = 'SSH_TIMEOUT'
SSH_AUTH_FAILED = 'SSH_AUTH_FAILED'
SSH_CONNECTION_REFUSED = 'SSH_CONNECTION_REFUSED'
SSH_NEGOTIATION_FAILED = 'SSH_NEGOTIATION_FAILED'
SSH_HOST_KEY_UNKNOWN = 'SSH_HOST_KEY_UNKNOWN'
SSH_HOST_KEY_MISMATCH = 'SSH_HOST_KEY_MISMATCH'
ARTIFACT_INVALID = 'ARTIFACT_INVALID'
EXPORT_FAILED = 'EXPORT_FAILED'

# Huawei VRP interactive-CLI specifics
HUAWEI_PROMPT_FAILED = 'HUAWEI_PROMPT_FAILED'
HUAWEI_PAGING_FAILED = 'HUAWEI_PAGING_FAILED'
HUAWEI_EXPORT_FAILED = 'HUAWEI_EXPORT_FAILED'

# VSOL OLT interactive-CLI specifics (ssh_pull, `show running-config`)
VSOL_PROMPT_FAILED = 'VSOL_PROMPT_FAILED'
VSOL_PRIVILEGED_MODE_FAILED = 'VSOL_PRIVILEGED_MODE_FAILED'
VSOL_EXPORT_FAILED = 'VSOL_EXPORT_FAILED'

# Storage primitive (local filesystem)
STORAGE_FAILED = 'STORAGE_FAILED'

# ENGINE-2: cooperative cancellation. Raised by a driver when it notices (at
# one of its own safe checkpoints — see docs/ENGINE_QUEUE.md) that the
# operator requested cancellation. Never retried: the job wasn't going to be
# resumed, it was stopped on purpose.
CANCELLED = 'CANCELLED'

# FTP received flow (spontaneous auto-backup and the manual diagnostic path)
FTP_FILE_INVALID = 'FTP_FILE_INVALID'
FTP_STORAGE_FAILED = 'FTP_STORAGE_FAILED'
FTP_RECEIVE_TIMEOUT = 'FTP_RECEIVE_TIMEOUT'
A10_RECEIVE_TIMEOUT = 'A10_RECEIVE_TIMEOUT'
A10_VERSION_UNSUPPORTED = 'A10_VERSION_UNSUPPORTED'
A10_TRANSFER_FAILED = 'A10_TRANSFER_FAILED'
A10_RECEIVER_UNAVAILABLE = 'A10_RECEIVER_UNAVAILABLE'

# FTP account/policy rejection — decided on the Laravel side
# (EngineJobService::receiveFtp), listed here so the catalogue stays complete
# for anything on the Python side that inspects these codes (e.g. quarantine
# reasons in ftp_incoming.py reuse the same vocabulary).
INVALID_ACCOUNT = 'invalid_account'
UNSUPPORTED_DEVICE = 'unsupported_device'
MISSING_BACKUP_POLICY = 'missing_backup_policy'
INVALID_BACKUP_POLICY = 'invalid_backup_policy'

# Errors that are plausibly transient — a caller (ENGINE-2's retry policy,
# once it exists) may reasonably try again without operator intervention.
RETRYABLE_CODES = frozenset({
    SSH_TIMEOUT,
    SSH_CONNECTION_REFUSED,
    SSH_CONNECT_FAILED,
    SSH_NEGOTIATION_FAILED,
    FTP_RECEIVE_TIMEOUT,
    A10_RECEIVE_TIMEOUT,
    STORAGE_FAILED,
    ENGINE_FAILED,
})

# Errors that will not resolve themselves by retrying (bad credentials,
# unsupported combination, content that failed validation, etc).
NON_RETRYABLE_CODES = frozenset({
    SSH_AUTH_FAILED,
    SSH_HOST_KEY_UNKNOWN,
    SSH_HOST_KEY_MISMATCH,
    ARTIFACT_INVALID,
    EXPORT_FAILED,
    HUAWEI_PROMPT_FAILED,
    HUAWEI_PAGING_FAILED,
    HUAWEI_EXPORT_FAILED,
    VSOL_PROMPT_FAILED,
    VSOL_PRIVILEGED_MODE_FAILED,
    VSOL_EXPORT_FAILED,
    UNSUPPORTED_VENDOR,
    UNSUPPORTED_POLICY,
    FTP_ACCOUNT_UNAVAILABLE,
    CREDENTIAL_INVALID,
    FTP_FILE_INVALID,
    A10_VERSION_UNSUPPORTED,
    A10_TRANSFER_FAILED,
    A10_RECEIVER_UNAVAILABLE,
    INVALID_ACCOUNT,
    UNSUPPORTED_DEVICE,
    MISSING_BACKUP_POLICY,
    INVALID_BACKUP_POLICY,
    CANCELLED,
})


def is_retryable(code):
    """Unknown codes default to non-retryable (fail closed)."""
    return code in RETRYABLE_CODES
