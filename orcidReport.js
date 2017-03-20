//set click behavior of summary table links and open diff container if specified in URL hash
$(document).ready(function() {
	$("#countsTable a").click(clickDiffLink);
	loadFromAnchor();
});

//close any open diff containers and open specified container
function toggleContainer(containerID) {
	$('.diffContainer.open').removeClass('open').addClass('closed');
	$(containerID).removeClass('closed').addClass('open');
}

//open diff container specified by link
function clickDiffLink(e) {
	//preventing default anchor scroll behavior in case of large diff list
    e.preventDefault();
    var targetContainer = $(e.target).attr('href');
    window.location.hash = targetContainer;
    toggleContainer(targetContainer);
}

//check if user specified diff container in URL and open
function loadFromAnchor() {
	if(location.href.indexOf('#') != -1) {
		var targetContainer = window.location.hash;
		toggleContainer(targetContainer);
	}
}