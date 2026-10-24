<?php

return [
    'unavailable'       => 'The manuals library is not available right now.',
    'not_found'         => 'The file does not exist in the library.',
    'upload_not_found'  => 'The upload does not exist, has expired or is not yours.',
    'too_many_pending'  => 'You have too many pending uploads. Finish them or wait for them to expire.',
    'extension_denied'  => 'Format not allowed. Accepted formats: :allowed.',
    'mime_mismatch'     => 'The declared file type does not match the extension.',
    'too_large'         => 'The file exceeds the maximum allowed size (:max MB).',
    'invalid_name'      => 'The file name is not valid.',
    'rejected_missing'  => 'The file did not reach storage completely.',
    'rejected_size'     => 'The uploaded file size does not match the declared size.',
    'rejected_type'     => 'The file content does not match its format.',
    'rejected_active_content' => 'The PDF contains JavaScript, actions or embedded files and is not accepted in the library.',
    'rejected_changed'        => 'The file changed or disappeared from storage before it was published. Upload it again.',
    'rejected_unscannable'    => 'The PDF could not be fully verified (file too large or read error) and is not accepted in the library.',
    'storage_unavailable'     => 'The library is not available right now. Try again in a few seconds.',
    'finalize_in_progress'    => 'This upload is already being finalized. Wait a few seconds.',
];
