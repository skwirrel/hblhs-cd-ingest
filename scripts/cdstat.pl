#!/usr/bin/perl
# Returns the CDROM_DRIVE_STATUS ioctl value for the given device.
# Output is a single integer on stdout:
#   0 = CDS_NO_INFO
#   1 = CDS_NO_DISC
#   2 = CDS_TRAY_OPEN
#   3 = CDS_DRIVE_NOT_READY
#   4 = CDS_DISC_OK
#
# Usage: perl cdstat.pl /dev/sr0

sysopen(my $fd, $ARGV[0], 2048) or die("unable to open device");
print ioctl($fd, 0x5326, 1);
close($fd);
