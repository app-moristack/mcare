<?php

return ['nursing_cosign_required' => true, 'schema_version' => '2026-11-v1', 'forms' => json_decode(file_get_contents(resource_path('assessment-fields.json')), true, 512, JSON_THROW_ON_ERROR)];
