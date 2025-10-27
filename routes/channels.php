<?php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('calls', fn() => true);

