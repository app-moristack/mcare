<?php

return ['schema_version' => '2026-11-v1', 'forms' => json_decode(file_get_contents(resource_path('clinical-fields.json')), true, 512, JSON_THROW_ON_ERROR)];
