<?php
declare(strict_types=1);

/** Does each machine get its own look? Getting it wrong is silent: a Kubuntu in Ubuntu's colours, or
 * an unknown desktop dressed as one it is not.
 */

use Desktop\LinuxDistro;
use Tester\Assert;

require dirname(__DIR__) . "/bootstrap.php";

$ubuntu = "PRETTY_NAME=\"Ubuntu 24.04 LTS\"\nNAME=\"Ubuntu\"\nID=ubuntu\nID_LIKE=debian\n";

Assert::same(LinuxDistro::Ubuntu, LinuxDistro::detect($ubuntu, 'ubuntu:GNOME', false));
Assert::same(LinuxDistro::Kubuntu, LinuxDistro::detect($ubuntu, 'KDE', false));
// Xubuntu has Ubuntu's ID too, and no look of its own yet.
Assert::null(LinuxDistro::detect($ubuntu, 'XFCE', false));
Assert::null(LinuxDistro::detect($ubuntu, '', false));

// ID_LIKE must not count: Mint names ubuntu there, and the quotes are optional.
Assert::same(LinuxDistro::Mint, LinuxDistro::detect("ID=linuxmint\nID_LIKE=\"ubuntu debian\"\n", 'X-Cinnamon', false));
Assert::same(LinuxDistro::Mint, LinuxDistro::detect("ID=\"linuxmint\"\n", 'XFCE', false));
Assert::null(LinuxDistro::detect("ID=pop\nID_LIKE=\"ubuntu debian\"\n", 'GNOME', false));

// Omarchy leaves Arch's ID, so only its folder says so; plain Arch stays unknown, and so does another
// session on the same machine.
Assert::same(LinuxDistro::Omarchy, LinuxDistro::detect("ID=arch\n", 'Hyprland', true));
Assert::null(LinuxDistro::detect("ID=arch\n", 'Hyprland', false));
Assert::null(LinuxDistro::detect("ID=arch\n", 'GNOME', true));

// Sources disagree on Kubuntu's ID, so either one counts.
Assert::same(LinuxDistro::Kubuntu, LinuxDistro::detect("ID=kubuntu\nID_LIKE=\"debian ubuntu\"\n", 'KDE', false));

Assert::null(LinuxDistro::detect('', '', false));
Assert::same('kubuntu-linux', LinuxDistro::Kubuntu->bodyClass());
