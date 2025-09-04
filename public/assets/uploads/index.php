<?php
// public/assets/uploads/index.php
// Simple guard to prevent listing the uploads directory.
http_response_code(403);
exit('Forbidden');
