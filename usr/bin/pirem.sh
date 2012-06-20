#!/bin/bash -
#===============================================================================
#          FILE:  pirem.sh
#         USAGE:  ./pirem.sh <opts>
#                 run as a cronjob daily at 00:30
#                 and fridays at 23:30
#
#    DESCRIPTION:  wrapper-script to remind pirates of events in a
#                  google-calendar via twitter, mailinglist, and wiki
#
#        OPTIONS:  try --help
#   REQUIREMENTS:  modded gcalcli as distributed with this script, python,
#                  ttytter, sendEmail, lynx
#         AUTHOR:  Marco Markgraf, marco-m-aus-f@gmx.net
#        CREATED:  01.03.2012 16:15:46 CET
#===============================================================================

function __init () {
## set constant vars
    export LANG=C # avoid locale issues...
    CFGDIR='/etc/pirem'
    CONFIG="$CFGDIR/pirem.conf"
    SHAREDIR='/usr/share/pirem'
    TEMPFILE=$(mktemp pirem.XXXXXXXXXX)
    MESSAGEFILE=$(mktemp piremsg.XXXXXXXXXX)
    DAYSTART=$(date -d "today 00:01" +%FT%R)
    DAYEND=$(date -d "2 days 23:59" +%FT%R)
    WEEKSTART=$(date -d 'next Monday 00:01' +%FT%R)
    WEEKEND=$(date -d 'Sunday next week 23:59' +%FT%R)
    TODAY=$(date +%F)
    TOMMOROW=$(date -d 'tomorrow' +%F)
    TWODAYS=$(date -d '2 days' +%F)
    WEEKLY='false'
    WIKIBOTPASS='TopSecret'
## source config file
    if [[ -r $CONFIG ]] ; then
        __debug "$FUNCNAME: Sourcing file $CONFIG"
        source $CONFIG
    else
        echo "ERROR: can't source configuration file: $CONFIG"
        echo "ERROR: You need to fix this!"
        exit 1
    fi
} # ----------  end of function __init  ----------

function __usage () {
cat <<-END_USAGE

[1mUSAGE:[0m ./pirem.sh -[hCDNVcuptw] <parameters>

    This script is meant to be run as a cronjob, daily at 00:30.
    Maybe you want to run in on Friday 23:30 with the -w option, too.
    Settings are done in __init , but you can pass some as options:

[1mOPTIONS:[0m
 [1m   -h    --help[0m
        Print this usage-message and exit

 [1m   -C    --config <path>[0m
        Set alternate /path/to/config-file. Use '-C' as the first of
        your options, to avoid further opts being overwritten.

 [1m   -D    --debug[0m
        Print debug-info during execution.

 [1m   -N    --noop[0m
        Do not send any tweets, mails...; aka 'dry-run'.

 [1m   -V    --version[0m
        Print version-information and exit.

 [1m   -c    --cal <name>[0m
        Select your google-calendar by name. If not set, will use 'default'.

 [1m   -u    --user <user>[0m
        Your google-username.

 [1m   -p    --password <path>[0m
        The path to your base64-coded password-file.

 [1m   -r    --mailrc=<path>[0m
        Path to rcfile for /usr/bin/mail

 [1m   -t    --ttytterauth[0m
        Name the keyfile for ttytter.

 [1m   -w    --weekly[0m
        Send an agenda of next weeks events.

[1mFORMAT OF CALENDAR-ENTRIES[0m
    Calendar entries need to start with a short paragraph to be tweeted,
    followed by an empty line and an optional more detailed description.

[1mBEFORE YOU RUN IT THE FIRST TIME[0m
    set up /etc/pirem/pirem.conf or an alternate conf to pass with -C
    set up ttytter
    set up your google-calender, of course...
    set up /etc/at.allow and /etc/cron.allow
    
[1mBUGS:[0m
    send bug-reports to marco-m-aus-f@gmx.net

END_USAGE
} # ----------  end of function __usage  ----------

function __fetch_opts () {
# fetch commandline options.
# use '-C' as the first of your options, to avoid your opts being overwritten.
    for arg in $@ ; do
        __debug "$FUNCNAME: $@"
        case $arg in
            -h|--help)
                __usage
                exit 0
                ;;
            -V|--version)
                echo "PirateReminder V0.7.5"
                exit 0
                ;;
            -C|--config)
                CONFIG="$2"
                if [[ -r $CONFIG ]] ; then
                    __debug "[$FUNCNAME] Sourcing file: $CONFIG"
                    source $CONFIG
                else
                    echo "cannot source file: $CONFIG"
                    exit 1
                fi
                ;;
            -D|--debug)
                DEBUG=true
                ;;
            -N|--noop)
                NOOP=true
                ;;
            -c|--cal)
                CALENDAR="$2"
                ;;
            -u|--user)
                CALUSER="$2"
                __debug "$FUNCNAME: CALUSER = \"$CALUSER\""
                ;;
            -p|--password)
                CALPW="$2"
                __debug "$FUNCNAME: CALPW = \"$CALPW\""
                if [[ ! -r $CALPW ]] ; then
                    echo "Can't read \"$CALPW\""
                    exit 1
                fi
                ;;
            -r|--mailrc)
                MAILRC="$2"
                __debug "$FUNCNAME: MAILRC = \"$MAILRC\""
                if [[ ! -r $MAILRC ]] ; then
                    echo "Can't read \"$MAILRC\""
                    exit 1
                fi
               ;;
            -t|--ttytterauth)
                TYTTERAUTH="-keyf=$2"
                ;;
            -w|--weekly)
                WEEKLY='yes'
		;;
            -*|--*)
                echo "I don't know what to do with \"$arg\""
                echo "Try --help"
                exit 1
                ;;
        esac    # --- end of case ---
        shift
    done
} # ----------  end of function __fetch_opts    ----------

function __debug () {
    if [[ "$DEBUG" == 'true' ]] ; then
        echo "$@"
    fi
} # ----------  end of function __debug  ----------

function __agenda () {
    local mystart="$1" myend="$2"
    #__debug "[$FUNCNAME] start: $mystart end: $myend"
    gcalcli-pirem --user $CALUSER --pw "$( base64 -d $CALPW )" --cal $CALENDAR --24hr --nc --tsv agenda $mystart $myend | grep .
} # ----------  end of function __agenda  ----------

function __tweet () {
    # three, two, one day(s) and $SOONER hours prior to events
    local tweet="$2" tweettime="$1"
    __debug "$FUNCNAME: at $tweettime <<< \"ttytter $TTYTTERAUTH -status='$tweet'\""
    if [[ "$NOOP" == 'false' ]] ; then
        at $tweettime <<< "ttytter $TTYTTERAUTH -status='$tweet'"
    fi
} # ----------  end of function __tweet  ----------

function __message_id () {
    # create a hopefully uniqe message-id for mails
    local mydate="$1" subject="$2" messid=''
    messid=$(echo "$mydate $subject $MAILTO" | sha1sum )
    RETVAL="<${messid%%  -}@$(hostname --fqdn)>"
    __debug "$FUNCNAME: $mydate $subject $RETVAL"
} # ----------  end of function __message_id    ----------

function __ml () {
    # send reminders to mailinglist via sendEmail
    # - three days prior to the event
    # - one day prior to the event, reply to the first mail

    local mailtime="$1" mydate="$2" subject="$3" message="$4"
    local inreplyto='' msg_ID='' mailrccmd=''

    __message_id "$mydate" "$subject"
    msg_ID="$RETVAL"
    # calculate this very same id if I'm replying to my first mail
    if [[ "${subject:0:4}" == 'Re: ' ]] ; then
        __message_id "$mydate" "${subject#Re: }"
        inreplyto="$RETVAL"
    fi

	if [[ -x /usr/bin/sendemail ]]; then
		__debug "$FUNCNAME: at $mailtime <<< sendemail -q $MAILSERVER $MAILUSER $MAILPASSWORD \
		-f $FROM -t $MAILTO \
		-u '$subject' -m '$message' \
		-o message-charset=utf-8 \
		-o message-header='Message-ID: $msg_ID' \
		-o message-header='In-Reply-To: $inreplyto'"

		if [[ "$NOOP" == 'false' ]] ; then
            #NOTE: if $inreplyto is empty, the In-Reply-To-Header will not appear,
            #      which is a good thing.
            at $mailtime <<< "sendemail -q $MAILSERVER $MAILUSER $MAILPASSWORD \
            -f $FROM -t $MAILTO \
            -u '$subject' -m '$message' \
            -o message-charset=utf-8 \
            -o message-header='Message-ID: $msg_ID' \
            -o message-header='In-Reply-To: $inreplyto'"
		fi
	else
		# serveradmin needs to set up user/emailadress for the script!
        if [[ -n "$MAILRC" ]] ; then
            mailrccmd="--rcfile=$MAILRC"
        fi
		__debug "$FUNCNAME: at $mailtime <<< \"echo -e \'$message\' | $MAILRC mail --subject=\'$subject\' --append=\'Message-ID: $msg_ID\' --append=\'In-Reply-To: $inreplyto\' $MAILTO"
		if [[ "$NOOP" == 'false' ]] ; then
            at $mailtime <<< "echo -e '$message' | mail $mailrccmd --subject='$subject' --append='Message-ID: $msg_ID' --append='In-Reply-To: $inreplyto' $MAILTO"
		fi
    fi
} # ----------  end of function __ml    ----------

function __wiki () {
    # Update Piratenwiki: http://wiki.piratenpartei.de/BW:Stammtisch_Freiburg
    # Remember restrictions for bots in the wiki,
    # see http://wiki.piratenpartei.de/Piratenwiki:Bots

    local mydate="$1" mytime="$2" mytitle="$3" mylocation="$4"
    local urldata="date=${mydate}&time=${mytime}&title=${mytitle}&location=${mylocation}&password=$WIKIBOTPASS"
    local encodedUrl=$(echo "$urldata" | sed -f ${SHAREDIR}/urlencode.sed)
    local url="${WIKIBOT}${urldata}"
    __debug "[$FUNCNAME] \$url = \"$url\""
    if [[ $NOOP == 'false' ]]; then
        lynx -dump -cmd_script=${SHAREDIR}lynx.cmd "$url"
    fi
} # ----------  end of function __wiki  ----------


function __tweetmsg () {
# generate the message to tweet,
# with message-template depending on date/time

#mylink=https://www.google.com/calendar/feeds/pirate.reminder@googlemail.com/private/full/$eventID
local tweet='' mydate="$1" mystart="$2" mytitle="$3"
case "$mydate" in
    "$TODAY")
        tweet="Heute $mystart: $mytitle $CALENDARLINK"
        tweet="Gleich: $mytitle $mylink $CALENDARLINK"

        ;;
    "$TOMMOROW")
        tweet="Morgen $mystart: $mytitle $CALENDARLINK"

        ;;
    "$TWODAYS")
        tweet="$mydate $mystart: $mytitle $CALENDARLINK"

        ;;
    *)

        ;;
esac    # --- end of case ---
} # ----------  end of function __tweetmsg  ----------


function __daily () {
    local tweet=''
    while IFS=$'\t' read -r mydate mystart myend mytitle myplace myevent; do
	__debug "[$FUNCNAME] $mydate $mystart $mytitle"
        # 'Kalenderwoche' und Feiertage überspringen
        if [[ "$myplace" == None && "$myevent" == None ]] ; then
            continue
        fi
        #tweet="${myevent%%\\n*}"
        tweet="$mydate $mystart $mytitle"
        # shorten tweet to 140 chars
        let "maxtweetlength=139-${#TWEETAS}"
        [[ ${#tweet} -gt $maxtweetlength ]] && tweet="${tweet:0:$maxtweetlength}"
        case "$mydate" in
            "$TODAY")
                updatetime=$(date -d "$SOONER $mystart" +%R)
                if [[ "$EARLYPOST" < "$updatetime" ]]; then
                    __tweet "$EARLYPOST" "$tweet"
                fi
                __tweet "$updatetime" "$tweet"
                # today, I reply to the mail I sent "two_days"
                __ml "$EARLYPOST" "$mydate" "Re: $mytitle" "$myevent"
                ;;
            "$TOMMOROW")
                __tweet "$MIDDLEPOST" "$tweet"
                __tweet "$LATEPOST" "$tweet"
                ;;
            "$TWODAYS")
                __tweet "$LATEPOST" "$tweet"
                __ml "$EARLYPOST" "$mydate" "$mytitle" "$myevent"
                ;;
            *)
                echo "[$FUNCNAME] ERROR: I don't know what to do with this date: \'$mydate\'"
                ;;
        esac    # --- end of case ---
    tweet=''
    done < $TEMPFILE
} # ----------  end of function __daily  ----------

function __weekly () {
    local message=''
    while IFS=$'\t' read -r mydate mystart myend mytitle myplace myevent; do
        if [[ "$mytitle" == "$WEEKLYTITLE"* ]] ; then
            __wiki "$mydate" "$mystart" "$mytitle" "$myplace"
        fi
        # 'Kalenderwoche' und Feiertage überspringen
        if [[ "$myplace" == None && "$myevent" == None ]] ; then
            continue
        fi
        #überblick generieren und __ml aufrufen
        echo -e "$mydate von $mystart bis $myend : $mytitle" >> $MESSAGEFILE
        echo -e "$myevent \n\n"|fold -s -w 74| sed -e 's/^/    /' -e 's/[ \t]*$//' >> $MESSAGEFILE
    done < $TEMPFILE
    message="$(cat $MESSAGEFILE)"
    __debug "[$FUNCNAME] __ml $EARLYPOST $MONDAY $WEEKLYTITLE $message"
    __ml "$EARLYPOST" "$MONDAY" "$WEEKLYSUBJECT" "$message"
} # ----------  end of function __weekly  ----------

function __main () {
    __init
    __fetch_opts $@
    case $WEEKLY in
        yes)
            __debug "$FUNCNAME: === fetching weekly agenda ==="
            __agenda $WEEKSTART $WEEKEND > $TEMPFILE
            __weekly
            ;;
        *)
            __debug "$FUNCNAME: === sending daily reminders === "
            __agenda $DAYSTART $DAYEND > $TEMPFILE
            __daily
            ;;
    esac    # --- end of case ---

    rm -f $TEMPFILE
    rm -f $MESSAGEFILE
} # ----------  end of function __main  ----------

__main $@
