<?php
/** Constants adminer defines at runtime with define('Adminer\NAME', ...).
* phpstan cannot see a namespaced constant declared that way, so they are declared here.
* Not loaded by anything at runtime -- phpstan reads this file and nothing else does.
*/

namespace Adminer;

const LANG = 'en';
const SERVER = '';
const DB = '';
const JUSH = 'sql';
