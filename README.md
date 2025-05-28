## MRBS - Department of Mathematics.

This repository contains the code for the Meeting Room Booking System currently
in use at the Department of Mathematics, University of Pisa. It is a fork of the
well-known [MRBS](https://mrbs.sourceforge.io/), with the following additions:

 * OAuth2 authentication, using the UNIPI system (or any other OAuth2 provider);

 * A partial API, available for authenticated clients with a secret token, that 
   allows to query free rooms, obtain detail of a booking entry, or book a new 
   room.
 
 * Minor styling tweaks: custom color and title, to quickly distinguish different 
   portals used in the same institution.

The main deployment for the Department of Mathematics is at 
[rooms.dm.unipi.it](https://rooms.dm.unipi.it/).


