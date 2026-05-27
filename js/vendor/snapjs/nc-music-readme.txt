This is the same version of Snap.js as is used in ownCloud 10 core.
The original creator jakiestfu never released the version 2.0.0 but this RC
is available in the branch https://github.com/jakiestfu/Snap.js/tree/v2.0.0-rc1.

Nextcloud server 34 removed the Snap.js for good but in earlier versions NC
used another fork of the project: https://github.com/JoeyAndres/Snap.js.
The fork is based on the last release version 1.9.3 from jakiestfu and doesn't
contain all the changes from the v2.0.0-rc1 branch.

This version shipped by NC had always (?) such a problem that, on touch screens,
closing the snapper from the toggle button made it impossible to open it again.
Also, the integration of the Snapper to NC was a pile of bit hacky workarounds
which may have contributed to this problem.

Snap.js library has the MIT license.