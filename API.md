# API


HTTP API have been added to MRBS, to allow integration with other systems, mainly booking
rooms for events and other activities in a semi-automatic way. 

The exposed endpoints are described in the following sections. 

## Details of an entry
```
/api.php?q=details&id=:ID:
```
Returns a JSON output describing the entry with given ID. If not present, 404 is returned, 
with a description of the error. Sample output is as follows:
```bash
$ curl -s -X POST --header "Authorization: Bearer XXXXXXXXXXXX" \ 
  "https://mrbs.example.com/api.php?q=details&id=:ID:" | jq 
{
  "id": ":ID:",
  "start_time": "1748332800",
  "end_time": "1748343600",
  "entry_type": "0",
  "repeat_id": null,
  "room_id": "42",
  "timestamp": "2025-05-18 09:16:58",
  "create_by": "username",
  "modified_by": "",
  "name": "Test meeting",
  "type": "I",
  "description": "",
  "reminded": null,
  "info_time": null,
  "info_user": null,
  "info_text": null,
  "ical_uid": "MRBS-6829a58a05a2c-dd9a6327@mrbs.example.com",
  "ical_sequence": "0",
  "ical_recur_id": null,
  "persons": "0",
  "allow_registration": "0",
  "registrant_limit_enabled": "0",
  "registrant_limit": "0",
  "registration_opens": "1209600",
  "registration_opens_enabled": "0",
  "registration_closes": "0",
  "registration_closes_enabled": "0",
  "awaiting_approval": false,
  "private": false,
  "tentative": false
}
```

## List of available rooms in a timeslot

```
/api.php?q=query&start_time=:timestamp:&end_time=:timestamp:
```
Query available rooms in a given slot (start time, end time). All times need to be UTC. Returns 
an array of rooms with their ID and name. Example output is as follows:
```bash
$ curl -s -X POST --header "Authorization: Bearer XXXXXXXXXXXX" "https://mrbs.example.com/api.php?q=query&start_time=1748350155&end_time=1748353755" | jq 
[
  {
    "id": "44",
    "area_id": "4",
    "name": "Saletta Riunioni",
    "capacity": 16
  },
  {
    "id": "49",
    "area_id": "4",
    "name": "Sala Riunioni Piano Terra",
    "capacity": 8
  }
]
```


## Booking a room

It is possible to create a new booking with the following endpoint
```
POST /api.php?q=book
```

The data posted should contain the data for the new booking in JSON format, 
for instance as follows:
```json
{
  "name": "Test booking", 
  "room_id": 23,
  "start_time": 1748331000,
  "end_time": 1748334600
}
```
The response contains the confirmation and the new booking:
```bash
$ curl -s --header "Authorization: Bearer XXXXXXXXXXXX" -X POST http://mrbs.example.com/api.php?q=book \
  --data '{ "room_id": 3, "start_time": 1749334600, "end_time": 1749338200, "name": "testing" }' | jq
{
  "status": "success",
  "booking": {
    "id": "7",
    "room_id": 3
  }
}
```
or a list of conflicts or violations as would be reported by MRBS, if the booking could not be made:
```bash
$ curl -s --header "Authorization: Bearer XXXXXXXXXXXX" -X POST http://mrbs.example.com/api.php?q=book \
  --data '{ "room_id": 3, "start_time": 1749334600, "end_time": 1749338200, "name": "testing api" }' | jq
{
  "error": "Booking failed",
  "violations": {
    "notices": [],
    "errors": []
  },
  "conflicts": [
    "<a href=\"view_entry.php?id=7\">testing api</a> (00:16 - Sunday 08 June 2025, yyy) <span>(<a href=\"index.php?view=day&amp;year=2025&amp;month=6&amp;day=8&amp;area=2&amp;room=3\">View Day</a> | <a href=\"index.php?view=week&amp;year=2025&amp;month=6&amp;day=8&amp;area=2&amp;room=3\">View Week</a> | <a href=\"index.php?view=month&amp;year=2025&amp;month=6&amp;day=8&amp;area=2&amp;room=3\">View Month</a>)</span>"
  ]
}
```
