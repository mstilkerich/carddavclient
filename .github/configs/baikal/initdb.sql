INSERT INTO addressbooks (id, principaluri, displayname, uri, description, synctoken) VALUES(1,'principals/citest','Default Address Book','default','Default Address Book for citest',1);
INSERT INTO calendars (id,synctoken,components) VALUES(1,1,'VEVENT,VTODO');
INSERT INTO calendarinstances (id,calendarid,principaluri,displayname,uri,description,calendarorder,calendarcolor,timezone) VALUES(1,1,'principals/citest','Default calendar','default','Default calendar',0,'','Europe/Berlin');
INSERT INTO principals (id,uri,email,displayname) VALUES(1,'principals/citest','citest@example.com','citest');
INSERT INTO users (id,username,digesta1) VALUES(1,'citest','583d4a8e7edca58122952a093ed573d7');
