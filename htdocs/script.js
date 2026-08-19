
window.debugMode = false;



///////////////////////////////////////
// == KEEP BODY UNDER THE TOP BAR == //
///////////////////////////////////////

// DASHBOARD //
const dashboard_topElement = document.querySelector('.dashboard-top-bar');
const dashboard_bottomElement = document.querySelector('.dashboard-main-content');

const dashboard_observer = new ResizeObserver(entries => {
  for (let entry of entries) {
    // Get the actual height including padding/borders
    const height = entry.contentRect.height;
    dashboard_bottomElement.style.marginTop = `${height+25}px`;
  }
});

dashboard_observer.observe(dashboard_topElement);

// PROFILE //

const profile_topElement = document.querySelector('.profile-top-bar');
const profile_bottomElement = document.querySelector('.profile-main-content');

const profile_observer = new ResizeObserver(entries => {
  for (let entry of entries) {
    // Get the actual height including padding/borders
    const height = entry.contentRect.height;
    profile_bottomElement.style.marginTop = `${height+25}px`;
  }
});

profile_observer.observe(profile_topElement);

// CARE PLAN //

const careplan_topElement = document.querySelector('.care-plan-top-bar');
const careplan_bottomElement = document.querySelector('.care-plan-main-content');

const careplan_observer = new ResizeObserver(entries => {
  for (let entry of entries) {
    // Get the actual height including padding/borders
    const height = entry.contentRect.height;
    careplan_bottomElement.style.marginTop = `${height+25}px`;
  }
});

careplan_observer.observe(careplan_topElement);

// ANALYTICS //

const analytics_topElement = document.querySelector('.analytics-top-bar');
const analytics_bottomElement = document.querySelector('.analytics-main-content');

const analytics_observer = new ResizeObserver(entries => {
  for (let entry of entries) {
    // Get the actual height including padding/borders
    const height = entry.contentRect.height;
    analytics_bottomElement.style.marginTop = `${height+25}px`;
  }
});

analytics_observer.observe(analytics_topElement);



////////////////////
// == DATABASE == //
////////////////////

class Guests {
	constructor() {
		this.data = [];
		this.loadedProfile = null;
		
		this.lastUpdated = null;
		this.updateTimer = null; // set to the setInterval handle once addGuests() starts polling
		
		this.timer = setInterval(() => { guests.calcTime(); }, 1000);
	}
	
	addGuests(guestsData) {
		for (const guestData of guestsData) {
			this.addGuest(guestData);
		}
		this.lastUpdated = Math.floor(Date.now() / 1000);
		// 2s rather than the original 5s -- meaningfully lower staleness
		// without meaningfully higher server cost, now that guest_updated_at
		// is indexed (see schema_v5_polling_index.sql). Permission checks
		// still run fresh on every single poll regardless of this interval,
		// so lowering it doesn't trade away any guest-privacy guarantees --
		// only how often we ask. Adjust this one number if you want to tune
		// it further; no other code needs to change either direction.
		this.updateTimer = setInterval(getUpdatedData, 2000);
	}
	
	addUpdatedGuests(guestsData) {
		if(!Object.hasOwn(guestsData, "time")) {
			if (window.debugMode)
				console.log("invalid 'addUpdatedGuests'")
			return [];
		}
		
		this.lastUpdated = guestsData.time;
		
		// Track which guest_ids are brand new (need a DOM node created) vs.
		// existing (already patched in place by updateGuest()/refreshInfo()),
		// so the caller only needs to touch the DOM for what actually changed
		// instead of re-rendering the whole dashboard every poll.
		const newGuestIds = [];
		for (const guestData of guestsData.data) {
			if (!this.data[guestData.guest_id]) {
				this.addGuest(guestData);
				newGuestIds.push(guestData.guest_id);
			} else {
				this.updateGuest(guestData);
			}
		}
		return newGuestIds;
	}
	
	addGuest(guestData) {
		this.data[guestData.guest_id] = new Guest(
			guestData.guest_id, guestData.guest_section, guestData.guest_room, guestData.guest_name, guestData.guest_time,
			guestData.section_name, guestData.room_name, guestData.location_id, guestData.location_name,
			guestData.dob, guestData.profile_picture, guestData.needs_reassignment
		);
		this.data[guestData.guest_id].addData();
	}
	
	updateGuest(guestData) {
		if(!this.data[guestData.guest_id]) {
			this.addGuest(guestData);
			return;
		}
		const guest = this.data[guestData.guest_id];
		guest.section = guestData.guest_section;
		guest.room = guestData.guest_room;
		guest.name = guestData.guest_name;
		guest.time = guestData.guest_time;
		guest.sectionName = guestData.section_name ?? guest.sectionName;
		guest.roomName = guestData.room_name ?? guest.roomName;
		guest.locationId = guestData.location_id ?? guest.locationId;
		guest.locationName = guestData.location_name ?? guest.locationName;
		guest.dob = guestData.dob ?? guest.dob;
		guest.profilePicture = guestData.profile_picture ?? guest.profilePicture;
		guest.needsReassignment = !!guestData.needs_reassignment;
		
		// Patch this guest's existing DOM node in place (location/name text,
		// alert-level classes, timer) instead of requiring a full dashboard
		// rebuild -- see Guest.refreshInfo() below.
		guest.refreshInfo();
		
		if(window.debugMode)
			console.log("Updated guest data for " + guestData.guest_name + "[" + guestData.guest_id + "]")
		
		showToast("Updated guest data for " + guestData.guest_name + "[" + guestData.guest_id + "]");
	}
	
	calcTime() {
		this.data.forEach((element, index, array) => {
			element.updateTime();
		});
	}
	
	updatePrivacy(on) {
		if(on === undefined)
			on = dashboard_private.checked;
		
		this.data.forEach((element, index, array) => {
			element.updatePrivate(on);
		});
	}
	
	loadProfile(guest) {
		if(guest === undefined)
			return;
		
		this.loadedProfile = guest;
	}
	
	displayProfile() {
		if (this.loadedProfile === null || this.loadedProfile === undefined)
			return;
		
		this.loadedProfile.loadProfile();
	}
	
	displayCareplan() {
		if (this.loadedProfile === null || this.loadedProfile === undefined)
			return;
		
		this.loadedProfile.loadCareplan();
	}
	
	displayAnalytics() {
		if (this.loadedProfile === null || this.loadedProfile === undefined)
			return;
		
		this.loadedProfile.loadAnalytics();
	}
}

class Guest {
	constructor(id, section, room, name, time, sectionName, roomName, locationId, locationName, dob, profilePicture, needsReassignment) {
		this.id = id;
		this.section = section;
		this.room = room;
		this.name = name;
		this.data = [];

		// Display names for the section/room/location (falls back to the
		// raw ID if a name wasn't provided -- keeps this constructor
		// backward-compatible with any call site that only ever passed the
		// first five arguments).
		this.sectionName = sectionName ?? ("Section " + section);
		this.roomName = roomName ?? ("Room " + room);
		this.locationId = locationId ?? null;
		this.locationName = locationName ?? "";

		this.dob = dob ?? null;
		this.profilePicture = profilePicture ?? null;
		this.needsReassignment = !!needsReassignment;
		
		// TEMP - for testing //
		if (Math.random() < 0.2)
			this.time = Math.floor(Date.now() - (Math.random() * 3600000));
		else
			this.time = 0;
		//this.time = time;
	}
	
	// "Location / Section / Room" display string used on the dashboard,
	// profile, care plan, and analytics pages -- one place so all four stay
	// consistent if the format ever changes.
	getLocationText() {
		return (this.locationName ? this.locationName + " / " : "") + this.sectionName + " / " + this.roomName;
	}
  
	alertLevels = [
	{type: "green", time: 300},
	{type: "yellow", time: 600},
	{type: "orange", time: 900},
	{type: "red", time: 0},
	];
  
	getAlertLevel(newTime) {
		if(newTime === undefined)
			newTime = this.time;
		
		newTime = this.getTimeInSeconds(newTime);

		if (this.time == 0)
			return "greenalert";
		else if (newTime < 600)
			return "yellowalert";
		else if (newTime < 1200)
			return "orangealert";
		else
			return "redalert";
	}
	
	getTime(iTime) {
		if(this.time == 0)
			return "0:00";
		
		if(iTime === undefined)
			iTime = this.time;
		
		iTime = this.getTimeInSeconds(iTime);

		let seconds = iTime % 60;
		iTime = iTime - seconds;
		if (seconds < 10)
			seconds = "0" + seconds;
		let minutes = (iTime % 3600) / 60;
		iTime = iTime - (minutes * 60)
		let hours = (iTime / 3600);
		if (hours > 0 && minutes < 10)
			minutes = "0" + minutes;
		
		if(hours > 0)
			return `${hours}:${minutes}:${seconds}`;
		else
			return `${minutes}:${seconds}`;
	}
	
	getTimeInSeconds(iTime) {
		if(iTime === undefined)
			iTime = this.time;
		
		return Math.floor((Date.now() - iTime) / 1000);
	}
	
	updateTime() {
		this.obj.querySelector(".dashboard-item-timer").innerHTML = this.getTime();
		
		this.obj.querySelector('.dashboard-item-content').classList.remove("greenalert", "yellowalert", "orangealert", "redalert");
		this.obj.querySelector('.dashboard-item-content').classList.add(this.getAlertLevel());
		this.obj.querySelector('.dashboard-item-timer').classList.remove("greenalert", "yellowalert", "orangealert", "redalert");
		this.obj.querySelector('.dashboard-item-timer').classList.add(this.getAlertLevel());
	}
	
	// Patches this guest's existing dashboard DOM node (location, name, alert
	// classes, timer) in place -- called after a data change comes in from
	// getUpdatedData(), as an alternative to destroying and recreating every
	// guest's node on every poll. Only touches this one guest's element, so
	// it's cheap even with hundreds of guests on screen, and it doesn't
	// disturb anything else on the page (scroll position, open toasts,
	// checked "confirm change" checkboxes on OTHER guests, etc.) the way a
	// full-list rebuild would.
	//
	// TODO: if this guest's section changes and the dashboard has an active
	// section/colour/duration filter (lastFilter 1-3) that this guest no
	// longer matches, this element won't be re-hidden until the next filter
	// change or full refresh -- dashboard_applyCurrentFilter() (called right
	// after this in getUpdatedData()) does handle that by re-running the
	// currently active filter against the whole list, so this is only a
	// concern if you ever call refreshInfo() somewhere that DOESN'T also
	// reapply the filter afterward.
	refreshInfo() {
		if (!this.obj) return; // no DOM node yet (shouldn't normally happen for an existing guest)
		this.obj.querySelector('#dashboard-location').innerHTML = this.getLocationText();
		this.obj.querySelector('#dashboard-name').innerHTML = this.name;
		this.updateTime(); // also refreshes alert-level classes + timer text
	}
	
	async confirmChange(){
		try {
			const response = await fetch('getdata.php?mode=confirmchange&id=' + encodeURIComponent(this.id));
			const dataArray = await response.json();
			
			if(!dataArray["success"]) {
				if (window.debugMode)
					console.error('Error fetching data:', dataArray["error"]);
				return;
			}
			
			if (window.debugMode)
				console.log("Success updating: " + this.id);
			
		} catch (error) {
			if (window.debugMode)
				console.error('Error fetching data:', error);
		}
	}
	
	updatePrivate(on) {
		if(on === undefined)
			on = true;
		
		if(on) {
			this.obj.querySelector('#dashboard-name').style.cssText = "display: none";
			this.obj.querySelector('.dashboard-side-image').style.cssText = "display: none";
		} else {
			this.obj.querySelector('#dashboard-name').style.cssText = "";
			this.obj.querySelector('.dashboard-side-image').style.cssText = "";
		}
	}
	
	loadProfile() {
		profileName.innerText = this.name;
		profileLocation.innerText = this.getLocationText();
		//profile_bottomElement.style.cssText = "";
		// Hook for facility.js (loaded separately, after this file) to render
		// the guest's location/section/room and, if the current user has
		// permission, reassignment controls. Guarded with typeof so this file
		// still works standalone if facility.js isn't present for some reason.
		if (typeof renderGuestFacilityControls === 'function') {
			renderGuestFacilityControls(this.id);
		}
	}
	
	loadCareplan() {
		careplanName.innerText = this.name;
		careplanLocation.innerText = this.getLocationText();
		//careplan_bottomElement.style.cssText = "";
		// Hook for careplan.js, same pattern as loadProfile()'s facility hook.
		if (typeof renderGuestCarePlan === 'function') {
			renderGuestCarePlan(this.id);
		}
	}
	
	loadAnalytics() {
		analyticsName.innerText = this.name;
		analyticsLocation.innerText = this.getLocationText();
		//analytics_bottomElement.style.cssText = "";
		

	const now = new Date();
	const data = this.data;
	const year = now.getFullYear();
	const month = now.getMonth() + 1;
	
    const daysInMonth = new Date(year, month, 0).getDate();

    const counts = new Array(daysInMonth).fill(0);
	const counts2 = new Array(daysInMonth).fill(0);

    data.forEach(entry => {
        const date = new Date(entry.start); // unix seconds

        if (
            date.getFullYear() === year &&
            (date.getMonth() + 1) === month
        ) {
            counts[date.getDate() - 1]++;
			//counts2[date.getDate() - 1] = (counts[date.getDate() - 1] / 5);
			counts2[date.getDate() - 1] = 5;
        }
    });

    const chartData = {
        labels: Array.from({ length: daysInMonth }, (_, i) => i + 1),
        data: counts,
		data2: counts2
    };


if(guests.chart !== undefined)
	guests.chart.destroy();
guests.chart = new Chart("myChart", {
    type: 'line',
    data: {
        labels: chartData.labels,
        datasets: [{
            label: 'Entries per Day',
            data: chartData.data,
            tension: 0.4,          // Smooth curve
            fill: true,            // Fill under line
            pointRadius: 3,        // Dot size
            pointHoverRadius: 6    // Hover size
        },
		{
            label: 'Average per Day',
            data: chartData.data2,
            tension: 0.4,          // Smooth curve
            fill: true,            // Fill under line
            pointRadius: 3,        // Dot size
            pointHoverRadius: 6    // Hover size
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                display: true
            }
        },
        scales: {
            y: {
				//max: 16,
                beginAtZero: true,
                ticks: {
                    precision: 0 // whole numbers only
                }
            }
        }
    }
});







/*

		
		const xValues = [];
		const yValues = [];
		
		this.data.forEach((element, index, array) => {
			xValues.push( { x: new Date(element.start).toISOString(), y: 1 } );
			xValues.push( { x: new Date(element.end).toISOString(), y: 0 } );
		});


const data = {
  datasets: [{
    label: 'Data Analysis',
    data: xValues,
    borderColor: 'rgb(75, 192, 192)',
    tension: 0.1,
    spanGaps: true // <--- IMPORTANT: Makes the line continuous
  }]
};

const config = {
  type: 'line',
  data: data,
  options: {
    scales: {
      x: {
        type: 'time',
        time: {
          unit: 'hour',
          displayFormats: {
            hour: 'HH:mm' // 24-hour format
          }
        },
        // Force 24-hour range
        min: new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString(),
        max: new Date(Date.now()).toISOString(),
        ticks: {
          source: 'auto'
        }
      },
      y: {
        beginAtZero: true
      }
    }
  }
};

		if(guests.chart !== undefined)
			guests.chart.destroy();
		guests.chart = new Chart("myChart", config);
		
		
*/		
		
		
		
		
		
		
/*		
		const xValues = [];
		const yValues = [];
		
		this.data.forEach((element, index, array) => {
			xValues.push( { x: new Date(element.start).toISOString(), y: 1 } );
			xValues.push( { x: new Date(element.end).toISOString(), y: 0 } );
		});


const data = {
  datasets: [{
    label: 'Data Analysis',
    data: xValues,
    borderColor: 'rgb(75, 192, 192)',
    tension: 0.1,
    spanGaps: true // <--- IMPORTANT: Makes the line continuous
  }]
};

const config = {
  type: 'line',
  data: data,
  options: {
    scales: {
      x: {
        type: 'time',
        time: {
          unit: 'hour',
          displayFormats: {
            hour: 'HH:mm' // 24-hour format
          }
        },
        // Force 24-hour range
        min: new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString(),
        max: new Date(Date.now()).toISOString(),
        ticks: {
          source: 'auto'
        }
      },
      y: {
        beginAtZero: true
      }
    }
  }
};

		if(guests.chart2 !== undefined)
			guests.chart2.destroy();
		guests.chart2 = new Chart("myChart2", config);
*/		
		
	}
	
	// temp - testing data from a database
	addData() {
		
		for (let i = 31; i >= 0; i--) {
			this.data = this.data.concat(addDay(i));
		}
		
		function addDay(past) {
			const now = Date.now();
			const minStart = now - ((past + 1) * (24 * 60 * 60 * 1000)); // 1 day ago
			const maxStart = now - (past * (24 * 60 * 60 * 1000)) - (20 * 60 * 1000);      // 20 minutes ago

			const count = Math.floor(Math.random() * 6) + 3; // 3–8 entries
			const ranges = [];

			function overlaps(start, end) {
				return ranges.some(r =>
					(start < r.end && end > r.start)
				);
			}

			while (ranges.length < count) {
				const start = Math.floor(
					Math.random() * (maxStart - minStart) + minStart
				);

				const duration = Math.floor(
					Math.random() * (20 * 60 * 1000)
				); // 0–20 minutes

				const end = start + duration;

				if (!overlaps(start, end)) {
					ranges.push({ start: start, end: end });
				}
			}

			// Sort chronologically
			ranges.sort((a, b) => a.start - b.start);

			return ranges;
		}
	}
}

//get user data from database.
async function getData() {
    try {
		const response = await fetch('getdata.php?mode=getdata');
        const dataArray = await response.json();
		
		if(!dataArray["success"]) {
			if (window.debugMode)
				console.error('Error fetching data:', dataArray["error"]);
			return;
		}
        
		guests.addGuests(dataArray["data"]["result"]);
		update_guests(); // full render: only happens once, for the initial load
    } catch (error) {
		if (window.debugMode)
			console.error('Error fetching data:', error);
    }
}

//get updated user data from database (polled every 2s -- see Guests.addGuests()).
//
// Deliberately a plain interval-polling fetch() rather than a persistent
// connection (SSE/WebSocket/long-poll): each call finishes in milliseconds
// and releases its PHP worker immediately, rather than holding one open
// continuously. With many concurrent viewers, that adds up to far less
// sustained server load than the same number of held-open connections
// would -- a held connection occupies a worker for its entire lifetime
// whether or not anything is actually happening, whereas dozens of quick
// polls every few seconds are, in aggregate, a much smaller ask. The
// tradeoff is latency (up to 5s before a change appears) rather than
// server resource usage, which is the right side of that tradeoff to be
// on when "dozens of people might have this open at once" is the concern.
async function getUpdatedData() {
    try {
		const response = await fetch('getdata.php?mode=getupdateddata&last_seen=' + encodeURIComponent(guests.lastUpdated));
        const dataArray = await response.json();
		
		if(!dataArray["success"]) {
			if (window.debugMode)
				console.error('Error fetching data:', dataArray["error"]);
			return;
		}
		
		const result = dataArray["data"]["result"];
		
		if (result.data.length === 0) {
			// Nothing changed since last poll -- most polls will land here.
			// Still worth advancing lastUpdated (matches the server's clock)
			// so the next poll's window starts from now rather than
			// re-requesting the same empty range, but there's nothing to
			// touch in the DOM, so skip straight out rather than doing any
			// rendering work at all.
			guests.lastUpdated = result.time;
			return;
		}
		
		// addUpdatedGuests() already patches existing guests' DOM nodes in
		// place (via Guest.refreshInfo()) as it processes each one; it only
		// hands back the guest_ids that were brand new, since those don't
		// have a DOM node yet and need one created.
		const newGuestIds = guests.addUpdatedGuests(result);
		if (newGuestIds.length > 0) {
			appendNewGuestElements(newGuestIds);
		}
		
		// Cheap regardless of how many guests changed: this only toggles
		// display/reorders nodes that already exist, it doesn't recreate
		// anything. Re-running it here (rather than only after structural
		// changes) keeps active filters/sort correct even when an existing
		// guest's section/alert-level changed enough to move it in/out of
		// the current filter or sort order.
		dashboard_applyCurrentFilter();
    } catch (error) {
		if (window.debugMode)
			console.error('Error fetching data:', error);
    }
}

const guests = new Guests();
getData();


const item = document.querySelector('.dashboard-item');

// Builds (but does not insert) the dashboard DOM node for one guest, and
// stores it as guest.obj. Shared by the full initial render (update_guests)
// and incremental inserts of newly-arrived guests (appendNewGuestElements)
// so there's exactly one place that knows how to construct a guest row.
function createGuestElement(guest) {
	guest.obj = item.cloneNode(true);
	guest.obj.style.cssText = "";

	guest.obj.id = guest.id;
	const input = guest.obj.querySelector('#myID');
	input.id = "item_" + guest.id;
	input.value = guest.id;
	guest.obj.querySelector('.dashboard-item-content').htmlFor = "item_" + guest.id;
	guest.obj.querySelector('#dashboard-location').innerHTML = guest.getLocationText();
	guest.obj.querySelector('#dashboard-name').innerHTML = guest.name;
	guest.obj.querySelector('.dashboard-item-content').classList.add(guest.getAlertLevel());
	guest.obj.querySelector('.dashboard-item-timer').classList.add(guest.getAlertLevel());
	guest.obj.querySelector('.dashboard-item-timer').innerHTML = guest.getTime();
	return guest.obj;
}

// Re-applies whichever dashboard filter/sort/search is currently active
// (tracked in the `lastFilter` global) to the guests already on screen.
// Extracted out of update_guests() so incremental updates can reapply the
// same logic without needing a full rebuild.
function dashboard_applyCurrentFilter() {
	switch (lastFilter) {
		case 1:
			dashboard_section_dropdown.dispatchEvent(new Event('change', { bubbles: true }));
			break;
		case 2:
			dashboard_colour_dropdown.dispatchEvent(new Event('change', { bubbles: true }));
			break
		case 3:
			dashboard_duration_dropdown.dispatchEvent(new Event('change', { bubbles: true }));
			break;
		case 4:
			dashboard_sort_dropdown.dispatchEvent(new Event('change', { bubbles: true }));
			break;
		case 5:
			dashboard_doSearch(dashboard_searchInput.value);
			break;
	}
}

// Appends DOM nodes for guests that just showed up for the first time (new
// guest_ids from a getUpdatedData() poll), without touching any of the
// existing, already-correct nodes for guests that didn't change.
function appendNewGuestElements(guestIds) {
	const container = document.querySelector('.dashboard-main-content');
	guestIds.forEach(id => {
		const guest = guests.data[id];
		if (!guest) return;
		container.appendChild(createGuestElement(guest));
	});
}

// Full rebuild of the dashboard list. Used for the initial load only --
// periodic 5s updates use appendNewGuestElements()/refreshInfo() instead so
// they don't tear down and recreate DOM nodes for guests that didn't
// change (which used to happen on every single poll, regardless of whether
// anything was actually different).
function update_guests() {
	// remove old children
	document.querySelector('.dashboard-main-content').replaceChildren();
	
	// add new children
	const container = document.querySelector('.dashboard-main-content');
	guests.data.forEach((element, index, array) => {
		container.appendChild(createGuestElement(element));
	});
	
	// apply default filter
	dashboard_applyCurrentFilter();
}

//////////////////////////
// == MENU / BUTTONS == //
//////////////////////////

class Menu {
	constructor(button) {
		this.clicked = null;
		this.buttons = [];
	}
	
	addButton(contentName, buttonName, fnc) {
		let button = {};
		button.content = document.getElementById(contentName);
		button.content.style.cssText = "display: none";
		button.button = document.querySelector('#' + buttonName);
		button.button.addEventListener('click', function() {
			menu.openPage(contentName);
		});
		button.fnc = fnc
		this.buttons[contentName] = button;
	}
	
	closePage() {
		if(this.clicked != null) {
			this.clicked.button.classList.remove('selected');
			this.clicked.content.style.cssText = "display: none";
		}
	}
	
	openPage(pageName) {
		if(this.buttons[pageName] === undefined)
			return;
		
		menu.closePage();
		this.buttons[pageName].content.style.cssText = "";
		this.buttons[pageName].button.classList.add('selected');
		menu.clicked = this.buttons[pageName];
		
		if(this.buttons[pageName].fnc !== undefined){
			guests[this.buttons[pageName].fnc]();
		}
	}
}

const menu = new Menu();
menu.addButton("dashboard", "dashboard-button");
menu.addButton("facility", "facility-button");
menu.addButton("profile", "profile-button", "displayProfile");
menu.addButton("care-plan", "care-plan-button", "displayCareplan");
menu.addButton("analytics", "analytics-button", "displayAnalytics");
menu.addButton("plugins", "plugins-button");
menu.addButton("admin", "admin-button");
menu.addButton("settings", "settings-button");
menu.addButton("login", "logout-button");
menu.openPage("dashboard");

///////////////////////////////////////
// == DASHBOARD - EVENT LISTENERS == //
///////////////////////////////////////

var lastFilter = 0;

const dashboard_section_dropdown = document.getElementById('dashboard-section-dropdown');

// Replaces the old hardcoded "Section 1/2/3" options with the real
// sections (grouped by location) that the current user actually has
// access to, using the same data locations.php already scopes correctly
// for the Facility Location page. Option *values* stay as raw section_ids
// -- the change handler below still just compares against that number --
// this only changes what's shown as the option *label*.
async function populateSectionFilterDropdown() {
	try {
		const res = await fetch('locations.php?action=list');
		const json = await res.json();
		if (!json.success) return;

		const currentValue = dashboard_section_dropdown.value;
		// Keep the first "[All]" option, replace everything after it.
		const allOption = dashboard_section_dropdown.querySelector('option[value="0"]');
		dashboard_section_dropdown.innerHTML = '';
		dashboard_section_dropdown.appendChild(allOption || new Option('Filter by section [All]', '0', true, true));

		json.data.locations.forEach(loc => {
			if (loc.sections.length === 0) return;
			const group = document.createElement('optgroup');
			group.label = loc.location_name;
			loc.sections.forEach(section => {
				group.appendChild(new Option(section.section_name, section.section_id));
			});
			dashboard_section_dropdown.appendChild(group);
		});

		// Restore whatever was selected before repopulating, if it still exists.
		if ([...dashboard_section_dropdown.options].some(o => o.value === currentValue)) {
			dashboard_section_dropdown.value = currentValue;
		}
	} catch (err) {
		if (window.debugMode) console.error('Failed to populate section filter:', err);
	}
}
populateSectionFilterDropdown();

dashboard_section_dropdown.addEventListener('change', (event) => {
	guests.data.forEach((element, index, array) => {
		if(event.target.value == 0)
			element.obj.style.cssText = "";
		else if(element.section == event.target.value)
			element.obj.style.cssText = "";
		else
			element.obj.style.cssText = "display: none";
	});
	
	lastFilter = 1;
});

const dashboard_colour_dropdown = document.getElementById('dashboard-colour-dropdown');
dashboard_colour_dropdown.addEventListener('change', (event) => {
	guests.data.forEach((element, index, array) => {
		if(event.target.value == 0)
			element.obj.style.cssText = "";
		else if(element.getAlertLevel() == event.target.value)
			element.obj.style.cssText = "";
		else
			element.obj.style.cssText = "display: none";
	});
	
	lastFilter = 2;
});

const dashboard_duration_dropdown = document.getElementById('dashboard-duration-dropdown');
dashboard_duration_dropdown.addEventListener('change', (event) => {
	guests.data.forEach((element, index, array) => {
		if(event.target.value == 0)
			element.obj.style.cssText = "";
		else if(element.section == event.target.value)
			element.obj.style.cssText = "";
		else
			element.obj.style.cssText = "display: none";
	});
	
	lastFilter = 3;
});

const dashboard_sort_dropdown = document.getElementById('dashboard-sort-dropdown');
dashboard_sort_dropdown.addEventListener('change', (event) => {
	const list = document.querySelector('.dashboard-main-content');
	const items = [...list.children];
	
	if (event.target.value == 0) {	// Time
		items.sort((a, b) => {
			if (a.id !== "" && b.id !== "") {
				if (guests.data[a.id].time == 0)
					return 1;
				if (guests.data[b.id].time == 0)
					return -1;
				return parseInt(guests.data[a.id].time) - parseInt(guests.data[b.id].time);
			}
		});
	} else if(event.target.value == 1) {	// Section
		items.sort((a, b) => {
			if(a.id !== "" && b.id !== "")
				if (guests.data[a.id].section !== guests.data[b.id].section)
					return parseInt(guests.data[a.id].section) - parseInt(guests.data[b.id].section);
				else
					return parseInt(guests.data[a.id].room) - parseInt(guests.data[b.id].room);
		});
	} else if(event.target.value == 2) {	// Name
		items.sort((a, b) => {
			if(a.id !== "" && b.id !== "")
				return guests.data[a.id].name.localeCompare(guests.data[b.id].name);
		});
	}

	items.forEach(node => list.appendChild(node));
	
	lastFilter = 4;
});

//sort_dropdown.value = '0'; 
dashboard_sort_dropdown.dispatchEvent(new Event('change', { bubbles: true }));


const btn_dashboard_unselect = document.getElementById('btn-dashboard-unselect');
btn_dashboard_unselect.addEventListener('click', (event) => {
	const allCheckboxes = document.querySelectorAll('[name="check-dashboard-item"]');
	allCheckboxes.forEach(checkbox => {
		checkbox.checked = false;
	});
});

const btn_dashboard_confirm = document.getElementById('btn-dashboard-confirm');
btn_dashboard_confirm.addEventListener('click', (event) => {
	const allCheckboxes = document.querySelectorAll('[name="check-dashboard-item"]');
	allCheckboxes.forEach(checkbox => {
		if (checkbox.checked == true)
			guests.data[checkbox.id.replace("item_", "")].confirmChange();
	});
	dashboard_sort_dropdown.dispatchEvent(new Event('change', { bubbles: true }));
	btn_dashboard_unselect.dispatchEvent(new Event('click', { bubbles: true }));
	guests.calcTime();
});

const dashboard_private = document.getElementById('dashboard-private');
dashboard_private.addEventListener('change', (event) => {
	guests.updatePrivacy();
});

//////////////////////////////////
// == DASHBOARD - SEARCH BAR == //
//////////////////////////////////

const doneSearchTypingInterval = 500; // Time in ms (1 second)

let dashboard_searchTimer; // Timer identifier
const dashboard_searchInput = document.querySelector('#dashboard-search');

// Listen for keyup (when user releases a key)
dashboard_searchInput.addEventListener('input', (event) => {
    clearTimeout(dashboard_searchTimer); // Reset the timer every time the user types
    dashboard_searchTimer = setTimeout(dashboard_doSearch, doneSearchTypingInterval, event.target.value);
});

function dashboard_doSearch(input) {
	guests.data.forEach((element, index, array) => {
		if(element.name.toLowerCase().includes(input.toLowerCase()))
			element.obj.style.cssText = "";
		else if(String(element.section).includes(input))
			element.obj.style.cssText = "";
		else if(String(element.room).includes(input))
			element.obj.style.cssText = "";
		else
			element.obj.style.cssText = "display: none";
	});
	
	lastFilter = 5;
}

////////////////////////////////
// == PROFILE - SEARCH BAR == //
////////////////////////////////

let profile_searchTimer; // Timer identifier
const profile_searchInput = document.querySelector('#profile-search');
const profile_searchContent = document.querySelector('.profile-search-results');
const profile_searchFailed = document.querySelector('.profile-search-failed');
const profile_searchLoading = document.querySelector('.profile-search-loading');
const profile_searchItem = document.querySelector('.profile-search-result');

profile_searchInput.addEventListener('input', (event) => {
    clearTimeout(profile_searchTimer); // Reset the timer every time the user types
	
	if (event.target.value == ""){
		profile_searchContent.replaceChildren();
		profile_searchContent.style.cssText = "display: none";
		return;
	}
	
    profile_searchTimer = setTimeout(profile_doSearch, doneSearchTypingInterval, event.target.value);
	
	profile_searchContent.replaceChildren();
	let obj = profile_searchLoading.cloneNode(true);
	obj.style.cssText = "";
	profile_searchContent.appendChild(obj);
	profile_searchContent.style.cssText = "";
});

function profile_doSearch(input) {
	profile_searchContent.replaceChildren();
	profile_searchContent.style.cssText = "display: none";
	if (input == "")
		return;
	let count = 0;
	guests.data.forEach((element, index, array) => {
		if(!(element.name.toLowerCase().includes(input.toLowerCase()) || String(element.section).includes(input) || String(element.room).includes(input)))
			return;
		count++;
		let obj = profile_searchItem.cloneNode(true);
		obj.style.cssText = "";
		profile_searchContent.appendChild(obj);
		obj.id = element.id;
		obj.textContent = element.name + " / Room " + element.room + " / Section " + element.section;
		obj.addEventListener('click', function() {
			profile_searchInput.value = "";
			profile_searchInput.dispatchEvent(new Event('input', { bubbles: true }));
			guests.loadProfile(guests.data[obj.id]);
			guests.displayProfile();
		});
	});
	profile_searchContent.style.cssText = "";
	if(count == 0){
		let obj = profile_searchFailed.cloneNode(true);
		obj.style.cssText = "";
		profile_searchContent.appendChild(obj);
	}
}

//////////////////////////////////
// == PROFILE - MAIN CONTENT == //
//////////////////////////////////

const profileName = document.querySelector('.profile-name');
const profileLocation = document.querySelector('.profile-location');

const btn_profile_careplan = document.getElementById('btn-profile-careplan');
btn_profile_careplan.addEventListener('click', (event) => {
	menu.openPage("care-plan");
});

const btn_profile_analytics = document.getElementById('btn-profile-analytics');
btn_profile_analytics.addEventListener('click', (event) => {
	menu.openPage("analytics");
});

//////////////////////////////////
// == CARE PLAN - SEARCH BAR == //
//////////////////////////////////

let careplan_searchTimer; // Timer identifier
const careplan_searchInput = document.querySelector('#care-plan-search');
const careplan_searchContent = document.querySelector('.care-plan-search-results');
const careplan_searchFailed = document.querySelector('.care-plan-search-failed');
const careplan_searchLoading = document.querySelector('.care-plan-search-loading');
const careplan_searchItem = document.querySelector('.care-plan-search-result');

careplan_searchInput.addEventListener('input', (event) => {
    clearTimeout(careplan_searchTimer); // Reset the timer every time the user types
	
	if (event.target.value == ""){
		careplan_searchContent.replaceChildren();
		careplan_searchContent.style.cssText = "display: none";
		return;
	}
	
    careplan_searchTimer = setTimeout(careplan_doSearch, doneSearchTypingInterval, event.target.value);
	
	careplan_searchContent.replaceChildren();
	let obj = careplan_searchLoading.cloneNode(true);
	obj.style.cssText = "";
	careplan_searchContent.appendChild(obj);
	careplan_searchContent.style.cssText = "";
});

function careplan_doSearch(input) {
	careplan_searchContent.replaceChildren();
	careplan_searchContent.style.cssText = "display: none";
	if (input == "")
		return;
	let count = 0;
	guests.data.forEach((element, index, array) => {
		if(!(element.name.toLowerCase().includes(input.toLowerCase()) || String(element.section).includes(input) || String(element.room).includes(input)))
			return;
		count++;
		let obj = careplan_searchItem.cloneNode(true);
		obj.style.cssText = "";
		careplan_searchContent.appendChild(obj);
		obj.id = element.id;
		obj.textContent = element.name + " / Room " + element.room + " / Section " + element.section;
		obj.addEventListener('click', function() {
			careplan_searchInput.value = "";
			careplan_searchInput.dispatchEvent(new Event('input', { bubbles: true }));
			guests.loadProfile(guests.data[obj.id]);
			guests.displayCareplan();
		});
	});
	careplan_searchContent.style.cssText = "";
	if(count == 0){
		let obj = careplan_searchFailed.cloneNode(true);
		obj.style.cssText = "";
		careplan_searchContent.appendChild(obj);
	}
}

////////////////////////////////////
// == CARE PLAN - MAIN CONTENT == //
////////////////////////////////////

const careplanName = document.querySelector('.care-plan-name');
const careplanLocation = document.querySelector('.care-plan-location');

const btn_careplan_profile = document.getElementById('btn-care-plan-profile');
btn_careplan_profile.addEventListener('click', (event) => {
	menu.openPage("profile");
});

const btn_careplan_analytics = document.getElementById('btn-care-plan-analytics');
btn_careplan_analytics.addEventListener('click', (event) => {
	menu.openPage("analytics");
});

//////////////////////////////////
// == ANALYTICS - SEARCH BAR == //
//////////////////////////////////

let analytics_searchTimer; // Timer identifier
const analytics_searchInput = document.querySelector('#analytics-search');
const analytics_searchContent = document.querySelector('.analytics-search-results');
const analytics_searchFailed = document.querySelector('.analytics-search-failed');
const analytics_searchLoading = document.querySelector('.analytics-search-loading');
const analytics_searchItem = document.querySelector('.analytics-search-result');

analytics_searchInput.addEventListener('input', (event) => {
    clearTimeout(analytics_searchTimer); // Reset the timer every time the user types
	
	if (event.target.value == ""){
		analytics_searchContent.replaceChildren();
		analytics_searchContent.style.cssText = "display: none";
		return;
	}
	
    analytics_searchTimer = setTimeout(analytics_doSearch, doneSearchTypingInterval, event.target.value);
	
	analytics_searchContent.replaceChildren();
	let obj = analytics_searchLoading.cloneNode(true);
	obj.style.cssText = "";
	analytics_searchContent.appendChild(obj);
	analytics_searchContent.style.cssText = "";
});

function analytics_doSearch(input) {
	analytics_searchContent.replaceChildren();
	analytics_searchContent.style.cssText = "display: none";
	if (input == "")
		return;
	let count = 0;
	guests.data.forEach((element, index, array) => {
		if(!(element.name.toLowerCase().includes(input.toLowerCase()) || String(element.section).includes(input) || String(element.room).includes(input)))
			return;
		count++;
		let obj = analytics_searchItem.cloneNode(true);
		obj.style.cssText = "";
		analytics_searchContent.appendChild(obj);
		obj.id = element.id;
		obj.textContent = element.name + " / Room " + element.room + " / Section " + element.section;
		obj.addEventListener('click', function() {
			analytics_searchInput.value = "";
			analytics_searchInput.dispatchEvent(new Event('input', { bubbles: true }));
			guests.loadProfile(guests.data[obj.id]);
			guests.displayAnalytics();
		});
	});
	analytics_searchContent.style.cssText = "";
	if(count == 0){
		let obj = analytics_searchFailed.cloneNode(true);
		obj.style.cssText = "";
		analytics_searchContent.appendChild(obj);
	}
}

////////////////////////////////////
// == ANALYTICS - MAIN CONTENT == //
////////////////////////////////////

const analyticsName = document.querySelector('.analytics-name');
const analyticsLocation = document.querySelector('.analytics-location');

const btn_analytics_profile = document.getElementById('btn-analytics-profile');
btn_analytics_profile.addEventListener('click', (event) => {
	menu.openPage("profile");
});

const btn_analytics_careplan = document.getElementById('btn-analytics-care-plan');
btn_analytics_careplan.addEventListener('click', (event) => {
	menu.openPage("care-plan");
});


/////////////////
// == TOAST == //
/////////////////

function showToast(message, type = 'info', duration = 3000) {
    // Create or get the main container
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        document.body.appendChild(container);
    }

    // Create the toast element and add the type class (success, error, etc.)
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    // Create the text content wrapper
    const textSpan = document.createElement('span');
    textSpan.innerText = message;
    toast.appendChild(textSpan);

    // Create the "X" close button
    const closeBtn = document.createElement('button');
    closeBtn.className = 'toast-close';
    closeBtn.innerHTML = '&times;'; // HTML code for the multiplication sign (X)
    toast.appendChild(closeBtn);

    // Append to container
    container.appendChild(toast);

    // Trigger transition entry
    requestAnimationFrame(() => {
        toast.classList.add('show');
    });

    // Function to smoothly remove the toast
    function dismissToast() {
        if (toast.classList.contains('show')) {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => {
                toast.remove();
            });
        }
    }

    // Set up the auto-delete timer
    const autoCloseTimer = setTimeout(dismissToast, duration);

    // Set up the manual click-to-close event
    closeBtn.addEventListener('click', () => {
        clearTimeout(autoCloseTimer); // Stop the auto-timer if clicked early
        dismissToast();
    });
}