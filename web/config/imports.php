<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sales file imports
    |--------------------------------------------------------------------------
    */

    // Uploaded files are turned into a plain rows file on this (private) disk.
    'disk' => 'local',

    // Largest upload accepted, in kilobytes.
    'max_file_kb' => 10240,

    // Most data rows accepted in one file. The whole file is read into memory
    // once, when it is uploaded, so this keeps that within the PHP memory limit.
    'max_rows' => 50000,

    // Rows handled by one queued job. Each job stays well under the queue
    // worker's time limit; the next job is queued when one finishes.
    'chunk_size' => 500,

    // Failed rows kept on the batch record for the screen. Every failed row
    // goes in the downloadable error report regardless.
    'stored_errors' => 100,

];
